<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\AccessGrant;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use App\I18n\Text;
use App\Repository\DomainRepository;
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
        private ResourceCatalog $catalog,
    ) {
    }

    public function isSuperAdmin(User $actor): bool
    {
        return $actor->hasRole(User::ROLE_SUPER_ADMIN);
    }

    /**
     * Platform administrators: ROLE_ADMIN, and the super-administrator (who may hold ROLE_SUPER_ADMIN alone).
     */
    public function isPlatformStaff(User $actor): bool
    {
        return $actor->isAdministrator();
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
     * @return Text|null why $actor may not set the owner and owner rights of $domain, null when allowed
     */
    public function ownerChangeDenial(User $actor, Domain $domain, ?User $owner, AccessExpression $ownerExpression): ?Text
    {
        if (null !== $owner) {
            $ownerDomain = $owner->getDomain();
            if (null !== $ownerDomain && !$domain->isWithin($ownerDomain)) {
                return new Text('access.policy.owner_must_belong_to_domain');
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
            return new Text('access.policy.you_cannot_change_owner_domain');
        }
        if (!$this->isWithinActorRights($actor, $domain, true, $ownerExpression)) {
            return new Text('access.policy.owner_rights_exceed_own_rights');
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
     * @return Text|null why $actor may not change the rights (grants, domain) of $target, null when allowed
     */
    public function rightsChangeDenial(User $actor, User $target): ?Text
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if ($actor->getId() === $target->getId()) {
            return new Text('access.policy.you_cannot_change_own_rights');
        }
        if ($this->isPlatformStaff($target)) {
            return new Text('access.policy.only_super_administrator_can_change_rights');
        }
        $domain = $target->getDomain();
        if (null === $domain || !$this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write)) {
            return new Text('access.policy.you_are_not_allowed_to_change');
        }
        if ($this->isAboveOrOwnerOf($target, $actor)) {
            return new Text('access.policy.you_cannot_change_rights_owner');
        }

        return null;
    }

    /**
     * @return Text|null why $actor may not give $target this grant, null when allowed
     */
    public function grantDenial(User $actor, User $target, Domain $domain, AccessExpression $expression, bool $includeSubdomains, ?AccessGrant $existing = null): ?Text
    {
        if (null !== ($denial = $this->rightsChangeDenial($actor, $target))) {
            return $denial;
        }
        if (null !== ($error = $this->unknownTypes($expression))) {
            return $error;
        }
        $targetDomain = $target->getDomain();
        if (null !== $targetDomain && !$domain->isWithin($targetDomain)) {
            return new Text('access.policy.username_belongs_to_domain_domain', ['{username}' => $target->getUsername(), '{domain}' => $targetDomain->getCode()]);
        }
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if (!$this->levelOn($actor, '@access', $domain, $includeSubdomains)->includes(AccessLevel::Write)) {
            return new Text('access.policy.you_are_not_allowed_to_manage_rights');
        }
        if (!$this->isWithinActorRights($actor, $domain, $includeSubdomains, $expression)) {
            return new Text('access.policy.rights_exceed_own_rights_on');
        }
        if (null !== $existing && !$this->isWithinActorRights($actor, $existing->getDomain(), $existing->includesSubdomains(), $existing->getExpression())) {
            return new Text('access.policy.current_rights_exceed_own_only');
        }

        return null;
    }

    /**
     * @return Text|null why $actor may not remove this grant, null when allowed
     */
    public function revokeDenial(User $actor, AccessGrant $grant): ?Text
    {
        if (null !== ($denial = $this->rightsChangeDenial($actor, $grant->getUser()))) {
            return $denial;
        }
        if (!$this->isSuperAdmin($actor) && !$this->isWithinActorRights($actor, $grant->getDomain(), $grant->includesSubdomains(), $grant->getExpression())) {
            return new Text('access.policy.rights_exceed_own_only_someone');
        }

        return null;
    }

    /**
     * @return Text|null why $actor may not move $target to $domain, null when allowed
     */
    public function domainChangeDenial(User $actor, User $target, ?Domain $domain): ?Text
    {
        if (null !== ($denial = $this->rightsChangeDenial($actor, $target))) {
            return $denial;
        }
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if (null === $domain || !$this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write)) {
            return new Text('access.policy.you_are_not_allowed_to_move');
        }

        return null;
    }

    /**
     * Domains $actor may create users in (and move users to): those where he holds @access:w.
     * The super-administrator may also create users outside any domain.
     *
     * @return list<Domain>
     */
    public function userDomainsFor(User $actor): array
    {
        if ($this->isSuperAdmin($actor)) {
            return $this->domains->subtree();
        }
        $root = $actor->getDomain();

        return null === $root ? [] : array_values(array_filter(
            $this->domains->subtree($root),
            fn (Domain $d): bool => $this->levelOn($actor, '@access', $d)->includes(AccessLevel::Write),
        ));
    }

    public function canCreateUsers(User $actor): bool
    {
        return $this->isSuperAdmin($actor) || [] !== $this->userDomainsFor($actor);
    }

    /**
     * Account changes made by someone else: identity, password link, 2FA reset, disabling, deletion.
     * Same bounds as for rights; one's own account is changed from the profile page.
     *
     * @return Text|null why $actor may not change the account of $target, null when allowed
     */
    public function accountChangeDenial(User $actor, User $target): ?Text
    {
        if ($actor->getId() === $target->getId()) {
            return new Text('access.policy.is_own_account_change_it');
        }
        if ($target->hasRole(User::ROLE_SUPER_ADMIN)) {
            return new Text('access.policy.super_administrator_account_is_changed');
        }
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        if ($this->isPlatformStaff($actor)) {
            return new Text('access.policy.platform_administrators_see_accounts_but');
        }
        if ($this->isPlatformStaff($target)) {
            return new Text('access.policy.only_super_administrator_can_change_accounts');
        }
        $domain = $target->getDomain();
        if (null === $domain || !$this->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write)) {
            return new Text('access.policy.you_are_not_allowed_to_manage_account');
        }
        if ($this->isAboveOrOwnerOf($target, $actor)) {
            return new Text('access.policy.you_cannot_manage_account_owner');
        }

        return null;
    }

    /**
     * @return Text|null why $actor may not rename $domain, null when allowed
     */
    public function domainRenameDenial(User $actor, Domain $domain): ?Text
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }
        $parent = $domain->getParent();
        if (null === $parent || !$this->levelOn($actor, '@domain', $parent)->includes(AccessLevel::Write)) {
            return new Text('access.policy.only_who_manages_parent_domain_can_rename');
        }

        return null;
    }

    /**
     * @return Text|null why $actor may not delete $domain, null when allowed
     */
    public function domainDeleteDenial(User $actor, Domain $domain, int $members, int $subdomains): ?Text
    {
        if (null !== $this->domainRenameDenial($actor, $domain)) {
            return new Text('access.policy.only_who_manages_parent_domain_can_delete');
        }
        if ($subdomains > 0) {
            return new Text('access.policy.domain_has_count_delete_or', ['{count}' => $subdomains]);
        }
        if ($members > 0) {
            return new Text('access.policy.domain_has_count_move_them', ['{count}' => $members]);
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
        foreach ($types as $type) {
            // And the facets named nowhere, which follow their type: "vehicle:r" also reads "vehicle.media".
            $base = AccessExpression::parentType($type) ?? $type;
            if (!AccessExpression::isReserved($base) && '~other' !== $base) {
                array_push($types, $base, $base.AccessExpression::FACET_SEPARATOR.'~other');
            }
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
     * @return Text|null an error naming the undeclared resource types and facets, null when all are known
     */
    public function unknownTypes(AccessExpression $expression): ?Text
    {
        return $this->catalog->unknownTypes($expression);
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
