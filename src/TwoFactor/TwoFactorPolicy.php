<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use App\Settings\SystemSettings;
use Psr\Clock\ClockInterface;

/**
 * When users must set up two-factor authentication.
 *
 * Mode (system setting, default APP_2FA_MODE):
 *  - optional: users enable it if they want, and may disable it again;
 *  - grace:    migration of existing accounts. Users without 2FA are asked at every login and may
 *              postpone ("Later") until the deadline (default APP_2FA_DEADLINE); from that date on it
 *              is required. With no deadline, postponing stays possible until a date is set;
 *  - required: users without 2FA must set it up at their next login.
 *
 * Administrators (ROLE_ADMIN, ROLE_SUPER_ADMIN) always need it, whatever the mode: they can impersonate.
 */
final readonly class TwoFactorPolicy
{
    public const MODE_OPTIONAL = 'optional';
    public const MODE_GRACE = 'grace';
    public const MODE_REQUIRED = 'required';

    public function __construct(
        private ClockInterface $clock,
        private SystemSettings $settings,
    ) {
    }

    public function mode(): string
    {
        return $this->settings->twoFactorMode();
    }

    public function deadline(): ?\DateTimeImmutable
    {
        return $this->settings->twoFactorDeadline();
    }

    /**
     * Must this user be sent to the enrollment page before going on?
     */
    public function mustPrompt(User $user): bool
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return false;
        }

        return $user->isAdministrator() || self::MODE_OPTIONAL !== $this->mode();
    }

    /**
     * May this user skip the enrollment for now?
     */
    public function canPostpone(User $user): bool
    {
        return !$user->isTotpAuthenticationEnabled()
            && !$user->isAdministrator()
            && self::MODE_GRACE === $this->mode()
            && !$this->isDeadlinePassed();
    }

    /**
     * May this user turn two-factor authentication off once enabled?
     */
    public function canDisable(User $user): bool
    {
        return self::MODE_OPTIONAL === $this->mode() && !$user->isAdministrator();
    }

    public function isDeadlinePassed(): bool
    {
        $deadline = $this->deadline();

        return null !== $deadline && $this->clock->now() >= $deadline;
    }

    /**
     * Whole days left before the deadline (0 on the last day), null without deadline.
     */
    public function daysLeft(): ?int
    {
        $deadline = $this->deadline();
        if (null === $deadline) {
            return null;
        }

        $seconds = $deadline->getTimestamp() - $this->clock->now()->getTimestamp();

        return max(0, (int) floor($seconds / 86400));
    }
}
