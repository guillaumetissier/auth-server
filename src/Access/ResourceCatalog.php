<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\AccessGrant;
use App\Entity\AccessProfile;
use App\Entity\Domain;
use App\Entity\ResourceFacet;
use App\Entity\ResourceType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use Psr\Log\LoggerInterface;

/**
 * The resource types and their facets that access expressions may name: declaring, describing and removing
 * them, and where they are used. Every change is written to the audit log.
 *
 * Codes never change once declared: resource servers test them in their code. A type or a facet still named
 * in some rights cannot be removed, so that no right silently loses its meaning.
 */
final readonly class ResourceCatalog
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccessResolver $resolver,
        private LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @return list<ResourceType>
     */
    public function types(): array
    {
        return $this->em->getRepository(ResourceType::class)->findBy([], ['code' => 'ASC']);
    }

    /**
     * Codes of every declared type and facet.
     *
     * @return array<string, true>
     */
    public function knownCodes(): array
    {
        $codes = [];
        foreach ($this->types() as $type) {
            $codes[$type->getCode()] = true;
            foreach ($type->getFacets() as $facet) {
                $codes[$facet->getCode()] = true;
            }
        }

        return $codes;
    }

    /**
     * @return string|null an error naming the undeclared types and facets, null when all are known
     */
    public function unknownTypes(AccessExpression $expression): ?string
    {
        $known = $this->knownCodes();
        $unknown = array_filter(
            $expression->namedTypes(),
            static fn (string $t): bool => !isset($known[$t]) && !\array_key_exists($t, ResourceType::RESERVED),
        );

        return [] === $unknown ? null : \sprintf('Unknown resource type(s) or facet(s): %s. Declare them first.', implode(', ', $unknown));
    }

    /**
     * Declares a type ("vehicle") or a facet of a declared type ("vehicle.pricing"), or changes its label
     * (and, for a facet, its description). Does not flush.
     *
     * @throws \InvalidArgumentException invalid code, reserved type, or facet of an undeclared type
     */
    public function declare(string $code, string $label, ?string $description = null, ?User $actor = null): ResourceType|ResourceFacet
    {
        $code = strtolower(trim($code));
        $label = '' === trim($label) ? $code : trim($label);
        if (AccessExpression::isReserved($code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is a built-in type: it cannot be declared, nor have facets.', $code));
        }

        $parent = AccessExpression::parentType($code);
        if (null === $parent) {
            $type = $this->em->find(ResourceType::class, $code);
            if (null === $type) {
                $type = new ResourceType($code, $label);
                $this->em->persist($type);
                $this->audit('resource_type.declared', $code, $actor, ['label' => $label]);
            } elseif ($type->getLabel() !== $label) {
                $type->setLabel($label);
                $this->audit('resource_type.described', $code, $actor, ['label' => $label]);
            }

            return $type;
        }

        $type = $this->em->find(ResourceType::class, $parent)
            ?? throw new \InvalidArgumentException(\sprintf('Unknown resource type "%s": declare it before its facets.', $parent));
        $facet = $this->em->find(ResourceFacet::class, $code);
        if (null === $facet) {
            $facet = new ResourceFacet($type, substr($code, \strlen($parent) + 1), $label, $description);
            $this->em->persist($facet);
            $this->audit('resource_facet.declared', $code, $actor, ['label' => $label]);
        } elseif ($facet->describe($label, $description)) {
            $this->audit('resource_facet.described', $code, $actor, ['label' => $label]);
        }

        return $facet;
    }

    /**
     * Removes a type (without facets) or a facet that no right names any more. Does not flush.
     *
     * @throws \InvalidArgumentException unknown code
     * @throws \DomainException          still used, or a type that still has facets
     */
    public function remove(string $code, ?User $actor = null): void
    {
        $code = strtolower(trim($code));
        $entity = null === AccessExpression::parentType($code)
            ? $this->em->find(ResourceType::class, $code)
            : $this->em->find(ResourceFacet::class, $code);
        if (null === $entity) {
            throw new \InvalidArgumentException(\sprintf('Unknown resource type or facet "%s".', $code));
        }

        if ($entity instanceof ResourceType && [] !== $entity->getFacets()) {
            throw new \DomainException(\sprintf('"%s" still has facets (%s): remove them first.', $code, implode(', ', array_map(static fn (ResourceFacet $f): string => $f->getCode(), $entity->getFacets()))));
        }
        $usages = $this->usages($code);
        if ([] !== $usages) {
            $shown = \array_slice($usages, 0, 5);
            throw new \DomainException(\sprintf('"%s" is still used in %d place(s): %s%s. Change these rights first.', $code, \count($usages), implode('; ', $shown), \count($usages) > \count($shown) ? '…' : ''));
        }

        if ($entity instanceof ResourceFacet) {
            $entity->getType()->removeFacet($entity);
        }
        $this->em->remove($entity);
        $this->audit($entity instanceof ResourceType ? 'resource_type.removed' : 'resource_facet.removed', $code, $actor);
    }

    /**
     * Where a type (or one of its facets) or a facet is named: rights of users, profiles, owner rights of
     * domains, and ACCESS_DEFAULT_EXPRESSION.
     *
     * @return list<string>
     */
    public function usages(string $code): array
    {
        $code = strtolower($code);
        $usages = [];
        foreach ($this->expressions() as [$where, $expression]) {
            if (self::names($expression, $code)) {
                $usages[] = $where;
            }
        }

        return $usages;
    }

    /**
     * Number of places where each type and facet is named (a type counts the places naming its facets).
     *
     * @return array<string, int>
     */
    public function usageCounts(): array
    {
        $counts = [];
        foreach ($this->expressions() as [, $expression]) {
            $codes = [];
            foreach ($expression->namedTypes() as $type) {
                $codes[$type] = true;
                if (null !== ($parent = AccessExpression::parentType($type))) {
                    $codes[$parent] = true;
                }
            }
            foreach (array_keys($codes) as $named) {
                $counts[$named] = ($counts[$named] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Every stored expression, with a description of where it is.
     *
     * @return iterable<array{string, AccessExpression}>
     */
    private function expressions(): iterable
    {
        /** @var list<AccessGrant> $grants */
        $grants = $this->em->createQuery('SELECT g, u, d FROM '.AccessGrant::class.' g JOIN g.user u JOIN g.domain d')->getResult();
        foreach ($grants as $grant) {
            // Its own rules only: what comes from a profile is reported once, for the profile.
            yield [\sprintf('rights of %s on %s', $grant->getUser()->getUsername(), $grant->getDomain()->getPath()), $grant->getAdjustments()];
        }
        foreach ($this->em->getRepository(AccessProfile::class)->findAll() as $profile) {
            yield [\sprintf('profile %s', $profile->getCode()), $profile->getExpression()];
        }
        foreach ($this->em->getRepository(Domain::class)->findAll() as $domain) {
            yield [\sprintf('owner rights of domain %s', $domain->getCode()), $domain->getOwnerExpression()];
        }
        yield ['ACCESS_DEFAULT_EXPRESSION', $this->resolver->defaultExpression()];
    }

    private static function names(AccessExpression $expression, string $code): bool
    {
        foreach ($expression->namedTypes() as $type) {
            if ($type === $code || AccessExpression::parentType($type) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $context
     */
    private function audit(string $event, string $code, ?User $actor, array $context = []): void
    {
        $this->auditLogger->info($event, ['code' => $code] + $context + [
            'actor' => $actor?->getUsername() ?? '(console)',
            'actor_id' => $actor?->getExternalUserId(),
        ]);
    }
}
