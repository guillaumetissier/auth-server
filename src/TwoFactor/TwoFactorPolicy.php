<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * When users must set up two-factor authentication.
 *
 * APP_2FA_MODE:
 *  - optional: users enable it if they want, and may disable it again;
 *  - grace:    migration of existing accounts. Users without 2FA are asked at every login and may
 *              postpone ("Later") until APP_2FA_DEADLINE; from that date on it is required.
 *              With an empty deadline, postponing stays possible until a date is set;
 *  - required: users without 2FA must set it up at their next login.
 *
 * Administrators (ROLE_ADMIN, ROLE_SUPER_ADMIN) always need it, whatever the mode: they can impersonate.
 */
final readonly class TwoFactorPolicy
{
    public const MODE_OPTIONAL = 'optional';
    public const MODE_GRACE = 'grace';
    public const MODE_REQUIRED = 'required';

    private string $mode;
    private ?\DateTimeImmutable $deadline;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%env(APP_2FA_MODE)%')] string $mode,
        #[Autowire('%env(default::APP_2FA_DEADLINE)%')] ?string $deadline,
    ) {
        if (!\in_array($mode, [self::MODE_OPTIONAL, self::MODE_GRACE, self::MODE_REQUIRED], true)) {
            throw new \InvalidArgumentException(\sprintf('APP_2FA_MODE must be optional, grace or required, "%s" given.', $mode));
        }
        $this->mode = $mode;

        $deadline = trim((string) $deadline);
        try {
            // A bare date ("2026-12-31") means: required from that day on, 00:00 in the server time zone.
            $this->deadline = '' === $deadline ? null : new \DateTimeImmutable($deadline);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('APP_2FA_DEADLINE must be a date such as 2026-12-31, "%s" given.', $deadline));
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function deadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    /**
     * Must this user be sent to the enrollment page before going on?
     */
    public function mustPrompt(User $user): bool
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return false;
        }

        return $user->isAdministrator() || self::MODE_OPTIONAL !== $this->mode;
    }

    /**
     * May this user skip the enrollment for now?
     */
    public function canPostpone(User $user): bool
    {
        return !$user->isTotpAuthenticationEnabled()
            && !$user->isAdministrator()
            && self::MODE_GRACE === $this->mode
            && !$this->isDeadlinePassed();
    }

    /**
     * May this user turn two-factor authentication off once enabled?
     */
    public function canDisable(User $user): bool
    {
        return self::MODE_OPTIONAL === $this->mode && !$user->isAdministrator();
    }

    public function isDeadlinePassed(): bool
    {
        return null !== $this->deadline && $this->clock->now() >= $this->deadline;
    }

    /**
     * Whole days left before the deadline (0 on the last day), null without deadline.
     */
    public function daysLeft(): ?int
    {
        if (null === $this->deadline) {
            return null;
        }

        $seconds = $this->deadline->getTimestamp() - $this->clock->now()->getTimestamp();

        return max(0, (int) floor($seconds / 86400));
    }
}
