<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;

/**
 * "What does it give": the effective level of a user per resource type in one domain,
 * exactly as resource servers will compute it from his token.
 */
final readonly class AccessPreview
{
    public function __construct(
        private AccessResolver $resolver,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array{type: string, label: string, level: AccessLevel, own: AccessLevel, reserved: bool}>
     */
    public function rows(User $user, Domain $domain): array
    {
        $acl = $this->resolver->aclFor($user);
        $rows = [];
        foreach ($this->em->getRepository(ResourceType::class)->findBy([], ['code' => 'ASC']) as $type) {
            $rows[] = $this->row($acl, $type->getCode(), $type->getLabel(), $domain, false);
        }
        foreach (ResourceType::RESERVED as $code => $label) {
            $rows[] = $this->row($acl, $code, $label, $domain, true);
        }

        return $rows;
    }

    /**
     * @return array{type: string, label: string, level: AccessLevel, own: AccessLevel, reserved: bool}
     */
    private function row(\GuillaumeTissier\ResourceGuard\Access\AccessControlList $acl, string $code, string $label, Domain $domain, bool $reserved): array
    {
        return [
            'type' => $code,
            'label' => $label,
            'level' => $acl->levelFor($code, $domain->getPath()),
            'own' => $acl->levelFor($code, $domain->getPath(), true),
            'reserved' => $reserved,
        ];
    }
}
