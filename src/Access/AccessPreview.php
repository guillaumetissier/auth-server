<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use GuillaumeTissier\ResourceGuard\Access\AccessControlList;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;

/**
 * "What does it give": the effective level of a user per resource type and facet in one domain,
 * exactly as resource servers will compute it from his token.
 */
final readonly class AccessPreview
{
    public function __construct(
        private AccessResolver $resolver,
        private ResourceCatalog $catalog,
    ) {
    }

    /**
     * Types, each followed by its facets, then the reserved types.
     *
     * @return list<array{type: string, label: string, level: AccessLevel, own: AccessLevel, reserved: bool, facet: bool}>
     */
    public function rows(User $user, Domain $domain): array
    {
        $acl = $this->resolver->aclFor($user);
        $rows = [];
        foreach ($this->catalog->types() as $type) {
            $rows[] = $this->row($acl, $type->getCode(), $type->getLabel(), $domain, false, false);
            foreach ($type->getFacets() as $facet) {
                $rows[] = $this->row($acl, $facet->getCode(), $facet->getLabel(), $domain, false, true);
            }
        }
        foreach (ResourceType::RESERVED as $code => $label) {
            $rows[] = $this->row($acl, $code, $label, $domain, true, false);
        }

        return $rows;
    }

    /**
     * @return array{type: string, label: string, level: AccessLevel, own: AccessLevel, reserved: bool, facet: bool}
     */
    private function row(AccessControlList $acl, string $code, string $label, Domain $domain, bool $reserved, bool $facet): array
    {
        // A facet goes up to write: "manage" (create, delete) concerns the whole resource.
        $cap = static fn (AccessLevel $level): AccessLevel => $facet && AccessLevel::Manage === $level ? AccessLevel::Write : $level;

        return [
            'type' => $code,
            'label' => $label,
            'level' => $cap($acl->levelFor($code, $domain->getPath())),
            'own' => $cap($acl->levelFor($code, $domain->getPath(), true)),
            'reserved' => $reserved,
            'facet' => $facet,
        ];
    }
}
