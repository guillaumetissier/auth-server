<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\AccessGrant;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Access\DomainPath;

/**
 * Who may see and change domains and rights, in the administration pages and commands.
 *
 *  - ROLE_SUPER_ADMIN: everything, everywhere;
 *  - ROLE_ADMIN: sees everything, changes nothing (read-only platform staff);
 *  - domain users: what their own rights say, through the reserved types "@access" (r = see the
 *    rights of the users of a domain, w = change them) and "@domain" (r = see subdomains, w = create
 *    subdomains and set their owner and owner rights), and never beyond their own rights: a grant,
 *    an owner expression, is always checked against what the actor holds himself on that domain
 *    (and on all its subdomains when it extends to them).
 *  - nobody changes his own rights, nor those of the owner of his domain or of any domain above it.
 */
final readonly class AccessPolicy
{
    public function __construct(
        private AccessResolver $resolver,
        private DomainRepository $domains,
        private EntityManagerInterface $em,
    ) {
    }

    public function isSuperAdmin(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function isPlatformStaff(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Level of $actor on a resource type in a domain, taking platform roles into account.
     */
    public function levelOn(User $actor, string $type, Domain $domain, bool $subtreeOnly = false, bool $own = false): AccessLevel
    {
        if ($this->isSuperAdmin($actor)) {
            return AccessLevel::Manage;
        }
        $level = $this->ownLevel($actor, $type, $domain, $subtreeOnly, $own);
        if ($this->isPlatformStaff($actor) && AccessExpression::isReserved($type)) {
            $level = AccessLevel::max($level, AccessLevel::Read);
        }

        return $level;
    }

    /**
     * True when $actor may open the administration pages at all.
     */
    public function canAdminister(User $actor): bool
    {
        if ($this->isPlatformStaff($actor)) {
            return true;
        }
        $domain = $actor->getDomain();

        return null !== $domain && [] !== $this->visibleDomains($actor);
    }

    /**
     * @return list<Domain> the domains $actor may see, in tree order
     */
    public function visibleDomains(User $actor): array
    {
        if ($this->isPlatformStaff($actor)) {
            return $this->domains->subtree();
        }
        $root = $actor->getDomain();
        if (null === $root) {
            return [];
        }

        return array_values(array_filter(
            $this->domains->subtree($root),
            fn (Domain $d): bool => $this->canViewDomain($actor, $d),
        ));
    }

    public function canViewDomain(User $actor, Domain $domain): bool
    {
        return $this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Read)
            || $this->levelOn($actor, '@domain', $domain)->includes(AccessLevel::Read);
    }

    public function canCreateSubdomain(User $actor, ?Domain $parent): bool
    {
        if (null === $parent) {
            return $this->isSuperAdmin($actor);
        }

        return $this->levelOn($actor, '@domain', $parent)->includes(AccessLevel::Write);
    }

    /**
     * @return string|null why $actor may not set the owner and owner rights of $domain, null when allowed
     */
    public function ownerChangeDenial(User $actor, Domain $domain, ?User $owner, AccessExpression $ownerExpression): ?string
    {
        if (null !== $owner) {
            $ownerDomain = $owner->getDomain();
            if (null !== $ownerDomain && !$domain->isWithin($ownerDomain)) {
                return 'The owner must belong to this domain or to a domain above it.';
            }
        }
        if (null !== ($error = $this->unknownTypes($ownerExpression))) {
            return $error;
        }
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        // Owners are set from above: nobody sets the rights of his own domain's owner.
        $parent = $domain->getParent();
        if (null === $parent || !$this->levelOn($actor, '@domain', $parent)->includes(AccessLevel::Write)) {
            return 'You cannot change the owner of this domain.';
        }
        if (!$this->isWithinActorRights($actor, $domain, true, $ownerExpression)) {
            return 'The owner rights exceed your own rights on this domain.';
        }

        return null;
    }

    public function canViewRightsOf(User $actor, User $target): bool
    {
        if ($this->isPlatformStaff($actor) || $actor->getId() === $target->getId()) {
            return true;
        }
        $domain = $target->getDomain();

        return null !== $domain && $this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Read);
    }

    /**
     * @return string|null why $actor may not change the rights (grants, domain) of $target, null when allowed
     */
    public function rightsChangeDenial(User $actor, User $target): ?string
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if ($actor->getId() === $target->getId()) {
            return 'You cannot change your own rights.';
        }
        if ($this->isPlatformStaff($target)) {
            return 'Only the super-administrator can change the rights of platform administrators.';
        }
        $domain = $target->getDomain();
        if (null === $domain || !$this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write)) {
            return 'You are not allowed to change the rights of this user.';
        }
        if ($this->isAboveOrOwnerOf($target, $actor)) {
            return 'You cannot change the rights of the owner of your domain.';
        }

        return null;
    }

    /**
     * @return string|null why $actor may not give $target this grant, null when allowed
     */
    public function grantDenial(User $actor, User $target, Domain $domain, AccessExpression $expression, bool $includeSubdomains, ?AccessGrant $existing = null): ?string
    {
        if (null !== ($denial = $this->rightsChangeDenial($actor, $target))) {
            return $denial;
        }
        if (null !== ($error = $this->unknownTypes($expression))) {
            return $error;
        }
        $targetDomain = $target->getDomain();
        if (null !== $targetDomain && !$domain->isWithin($targetDomain)) {
            return \sprintf('%s belongs to domain "%s": rights can only be granted on it or its subdomains.', $target->getUsername(), $targetDomain->getCode());
        }
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if (!$this->levelOn($actor, '@access', $domain, $includeSubdomains)->includes(AccessLevel::Write)) {
            return 'You are not allowed to manage rights on this domain.';
        }
        if (!$this->isWithinActorRights($actor, $domain, $includeSubdomains, $expression)) {
            return 'These rights exceed your own rights on this domain.';
        }
        if (null !== $existing && !$this->isWithinActorRights($actor, $existing->getDomain(), $existing->includesSubdomains(), $existing->getExpression())) {
            return 'The current rights exceed your own: only someone holding them can change them.';
        }

        return null;
    }

    /**
     * @return string|null why $actor may not remove this grant, null when allowed
     */
    public function revokeDenial(User $actor, AccessGrant $grant): ?string
    {
        if (null !== ($denial = $this->rightsChangeDenial($actor, $grant->getUser()))) {
            return $denial;
        }
        if (!$this->isSuperAdmin($actor) && !$this->isWithinActorRights($actor, $grant->getDomain(), $grant->includesSubdomains(), $grant->getExpression())) {
            return 'These rights exceed your own: only someone holding them can remove them.';
        }

        return null;
    }

    /**
     * @return string|null why $actor may not move $target to $domain, null when allowed
     */
    public function domainChangeDenial(User $actor, User $target, ?Domain $domain): ?string
    {
        if (null !== ($denial = $this->rightsChangeDenial($actor, $target))) {
            return $denial;
        }
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if (null === $domain || !$this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write)) {
            return 'You are not allowed to move users to this domain.';
        }

        return null;
    }

    /**
     * True when $target owns the domain of $actor or a domain above it: the people $actor answers to.
     */
    public function isAboveOrOwnerOf(User $target, User $actor): bool
    {
        $actorDomain = $actor->getDomain();
        if (null === $actorDomain) {
            return false;
        }
        foreach ($this->domains->ownedBy($target) as $owned) {
            if ($actorDomain->isWithin($owned)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delegation bound: $expression grants nothing $actor does not hold on $domain
     * (on every subdomain too when $includeSubdomains).
     */
    public function isWithinActorRights(User $actor, Domain $domain, bool $includeSubdomains, AccessExpression $expression): bool
    {
        $types = [...$expression->namedTypes(), ...array_keys(ResourceType::RESERVED), '~other'];
        foreach ($this->resolver->aclFor($actor)->entries as $entry) {
            // A type the actor holds at a capped level ("*:w tire:r") must be checked even if unnamed here.
            $types = [...$types, ...$entry->expression->namedTypes()];
        }
        $types = array_unique($types);
        foreach ($types as $type) {
            foreach ([false, true] as $own) {
                $granted = $expression->levelFor($type, $own);
                if (AccessLevel::None === $granted) {
                    continue;
                }
                if (!$this->ownLevel($actor, $type, $domain, $includeSubdomains, $own)->includes($granted)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return string|null an error naming the undeclared resource types, null when all are known
     */
    public function unknownTypes(AccessExpression $expression): ?string
    {
        $known = array_flip(array_map(static fn (ResourceType $t): string => $t->getCode(), $this->em->getRepository(ResourceType::class)->findAll()));
        $unknown = array_filter(
            $expression->namedTypes(),
            static fn (string $t): bool => !isset($known[$t]) && !\array_key_exists($t, ResourceType::RESERVED),
        );

        return [] === $unknown ? null : \sprintf('Unknown resource type(s): %s. Declare them first.', implode(', ', $unknown));
    }

    /**
     * Level from the actor's own rights only (no platform role). With $subtreeOnly, only the
     * entries covering every subdomain of $domain count: what holds for the whole subtree.
     */
    private function ownLevel(User $actor, string $type, Domain $domain, bool $subtreeOnly, bool $own): AccessLevel
    {
        $acl = $this->resolver->aclFor($actor);
        if (null !== $acl->userDomain && !DomainPath::isWithin($domain->getPath(), $acl->userDomain)) {
            return AccessLevel::None;
        }

        $level = AccessLevel::None;
        foreach ($acl->entries as $entry) {
            if (null !== $acl->userDomain && !DomainPath::isWithin($entry->domainPath, $acl->userDomain)) {
                continue;
            }
            if ($subtreeOnly && !$entry->includeSubdomains) {
                continue;
            }
            if ($entry->appliesTo($domain->getPath())) {
                $level = AccessLevel::max($level, $entry->expression->levelFor($type, $own));
            }
        }

        return $level;
    }
}
