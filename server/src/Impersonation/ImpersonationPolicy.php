<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Access\AccessPolicy;
use App\Entity\User;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Token\Impersonation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who may impersonate whom, how, and for how long.
 *
 *  - ROLE_SUPER_ADMIN (one employee): full rights, may impersonate regular users and admins;
 *  - ROLE_ADMIN: read-only (scopes reduced to IMPERSONATION_READONLY_SCOPES, writes refused by
 *    resource servers), may impersonate regular users only;
 *  - domain users holding the reserved right "@impersonate" on the target's domain: "r" read-only,
 *    "w" full rights. Never granted by default: a domain owner gets it only if his owner expression
 *    says so. They never impersonate platform staff, nor the owner of their domain or of a domain above;
 *  - nobody impersonates himself or a super-admin, and an impersonated session cannot impersonate.
 */
final readonly class ImpersonationPolicy
{
    /** @var list<string> */
    private array $readOnlyScopes;

    /**
     * @param list<string> $readOnlyScopes
     */
    public function __construct(
        #[Autowire('%env(csv:IMPERSONATION_READONLY_SCOPES)%')] array $readOnlyScopes,
        #[Autowire('%env(IMPERSONATION_TTL)%')] private string $sessionTtl,
        #[Autowire('%env(IMPERSONATION_ACCESS_TOKEN_TTL)%')] private string $accessTokenTtl,
        private AccessPolicy $access,
    ) {
        $this->readOnlyScopes = array_values(array_filter(array_map('trim', $readOnlyScopes)));
    }

    /**
     * @return string|null the mode $admin impersonates $target with, null when not allowed to impersonate
     */
    public function modeFor(User $admin, User $target): ?string
    {
        if ($admin->hasRole(User::ROLE_SUPER_ADMIN)) {
            return Impersonation::MODE_FULL;
        }
        if ($admin->hasRole(User::ROLE_ADMIN)) {
            return Impersonation::MODE_READ_ONLY;
        }
        $domain = $target->getDomain();
        if (null === $domain) {
            return null;
        }

        return match ($this->access->levelOn($admin, '@impersonate', $domain)) {
            AccessLevel::Write, AccessLevel::Manage => Impersonation::MODE_FULL,
            AccessLevel::Read => Impersonation::MODE_READ_ONLY,
            AccessLevel::None => null,
        };
    }

    /**
     * True when $user may impersonate someone at all (shows the impersonation entry points).
     */
    public function canImpersonateAnyone(User $user): bool
    {
        if ($user->hasRole(User::ROLE_ADMIN)) {
            return true;
        }
        $domain = $user->getDomain();

        return null !== $domain && $this->access->levelOn($user, '@impersonate', $domain, false)->includes(AccessLevel::Read);
    }

    /**
     * @return string|null why $admin may not impersonate $target, null when allowed
     */
    public function denialReason(User $admin, User $target): ?string
    {
        if (null === $this->modeFor($admin, $target)) {
            return 'You are not allowed to impersonate this user.';
        }
        if ($admin->getId() === $target->getId()) {
            return 'You cannot impersonate yourself.';
        }
        if ($target->hasRole(User::ROLE_SUPER_ADMIN)) {
            return 'The super-administrator cannot be impersonated.';
        }
        if ($target->hasRole(User::ROLE_ADMIN) && !$admin->hasRole(User::ROLE_SUPER_ADMIN)) {
            return 'Only the super-administrator can impersonate an administrator.';
        }
        if (!$admin->hasRole(User::ROLE_ADMIN) && $this->access->isAboveOrOwnerOf($target, $admin)) {
            return 'You cannot impersonate the owner of your domain.';
        }
        if (!$target->isEnabled()) {
            return 'This account is disabled.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function readOnlyScopes(): array
    {
        return $this->readOnlyScopes;
    }

    public function sessionTtl(): \DateInterval
    {
        return new \DateInterval($this->sessionTtl);
    }

    public function accessTokenTtl(): \DateInterval
    {
        return new \DateInterval($this->accessTokenTtl);
    }
}
