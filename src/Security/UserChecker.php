<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * Runs before the password check: a locked account does not even reveal whether the password was right.
     */
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isEnabled()) {
            throw new CustomUserMessageAccountStatusException('This account is disabled.');
        }

        if ($user->isLocked($this->clock->now())) {
            throw new CustomUserMessageAccountStatusException('This account is temporarily locked. Try again later.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
