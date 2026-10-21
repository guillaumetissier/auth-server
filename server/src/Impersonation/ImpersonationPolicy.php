<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Entity\User;
use GuillaumeTissier\OAuthResourceGuard\Token\Impersonation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who may impersonate whom, how, and for how long.
 *
 *  - ROLE_SUPER_ADMIN (one employee): full rights, may impersonate regular users and admins;
 *  - ROLE_ADMIN: read-only (scopes reduced to IMPERSONATION_READONLY_SCOPES, writes refused by
 *    resource servers), may impersonate regular users only;
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
    ) {
        $this->readOnlyScopes = array_values(array_filter(array_map('trim', $readOnlyScopes)));
    }

    /**
     * @return string|null the mode this user impersonates with, null when not allowed to impersonate
     */
    public function modeFor(User $admin): ?string
    {
        return match (true) {
            $admin->hasRole(User::ROLE_SUPER_ADMIN) => Impersonation::MODE_FULL,
            $admin->hasRole(User::ROLE_ADMIN) => Impersonation::MODE_READ_ONLY,
            default => null,
        };
    }

    /**
     * @return string|null why $admin may not impersonate $target, null when allowed
     */
    public function denialReason(User $admin, User $target): ?string
    {
        if (null === $this->modeFor($admin)) {
            return 'You are not allowed to impersonate users.';
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
