<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Event\AccountEvent;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Account-level brute-force protection, complementing the per-IP login throttling:
 * after MAX_ATTEMPTS consecutive failures (password or 2FA code), the account is locked
 * for LOCK_DURATION whatever the source IP.
 */
final readonly class LoginAttemptSubscriber implements EventSubscriberInterface
{
    public const MAX_ATTEMPTS = 10;
    public const LOCK_DURATION = 'PT30M';

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
            TwoFactorAuthenticationEvents::FAILURE => 'onTwoFactorFailure',
            TwoFactorAuthenticationEvents::COMPLETE => 'onTwoFactorComplete',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // Only a wrong password is a guess against the account. A locked account, the IP throttling
        // or an expired CSRF token reject the attempt before the password is checked: not counted.
        if (!$event->getException() instanceof BadCredentialsException) {
            return;
        }

        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        $username = $badge instanceof UserBadge ? $badge->getUserIdentifier() : null;
        $user = null !== $username ? $this->users->findOneByUsername($username) : null;
        if (null === $user) {
            return; // unknown username: nothing to lock, same response as for a wrong password
        }

        $this->registerFailure($user, 'password');
    }

    public function onTwoFactorFailure(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User) {
            $this->registerFailure($user, 'totp');
        }
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        // With 2FA enabled, the login is complete only once the code is verified (onTwoFactorComplete).
        if ($user->isTotpAuthenticationEnabled()) {
            return;
        }

        $this->registerSuccess($user, ['second_factor' => false]);
    }

    public function onTwoFactorComplete(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof User) {
            $this->registerSuccess($user, ['second_factor' => true]);
        }
    }

    private function registerFailure(User $user, string $factor): void
    {
        $locked = $user->registerFailedLogin($this->clock->now(), self::MAX_ATTEMPTS, new \DateInterval(self::LOCK_DURATION));
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_LOGIN_FAILED, $user, ['factor' => $factor]));
        if ($locked) {
            $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_LOCKED, $user, [
                'locked_until' => $user->getLockedUntil()?->format(\DATE_ATOM),
            ]));
        }
    }

    /**
     * @param array<string, scalar> $context
     */
    private function registerSuccess(User $user, array $context): void
    {
        $user->registerSuccessfulLogin($this->clock->now());
        $this->em->flush();

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_LOGIN_SUCCEEDED, $user, $context));
    }
}
