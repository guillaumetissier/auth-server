<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;
use App\Impersonation\ImpersonationConfirmations;
use App\Impersonation\ImpersonationContext;
use App\Impersonation\ImpersonationManager;
use App\Impersonation\ImpersonationPolicy;
use App\Repository\UserRepository;
use App\TwoFactor\TwoFactorPolicy;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Decides on authorization requests reaching /authorize (the user is already logged in, 2FA included).
 *
 *  - PKCE with S256 is mandatory for every client (OAuth 2.1), not only public ones;
 *  - users without TOTP are sent to the enrollment page first, as TwoFactorPolicy decides
 *    (during the grace period they may postpone it once per login session);
 *  - "impersonate=<external user id>": an administrator asks for a code for another user. The
 *    request goes through the confirmation page (reason + fresh TOTP code) and the code is issued
 *    for the target user, bound to the impersonation session;
 *  - clients are first-party (registered by an administrator), so consent is implicit.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final readonly class AuthorizationRequestListener
{
    public const IMPERSONATE_PARAMETER = 'impersonate';
    /** Set by AccountController::postpone() when the user chose "Later" during the grace period. */
    public const POSTPONED_SESSION_KEY = 'two_factor_postponed';

    public function __construct(
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private ImpersonationPolicy $impersonationPolicy,
        private ImpersonationConfirmations $confirmations,
        private ImpersonationManager $impersonations,
        private ImpersonationContext $impersonationContext,
        private UserRepository $users,
        private ClockInterface $clock,
        private TwoFactorPolicy $twoFactorPolicy,
    ) {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        if (null === $event->getCodeChallenge() || 'S256' !== $event->getCodeChallengeMethod()) {
            throw OAuthServerException::invalidRequest('code_challenge', 'PKCE with code_challenge_method=S256 is required.');
        }

        $user = $event->getUser();
        $requestUri = $this->requestStack->getCurrentRequest()?->getRequestUri() ?? '/';

        if ($user instanceof User && $this->twoFactorPolicy->mustPrompt($user) && !$this->postponedInThisSession($user)) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('app_2fa_setup', ['return_to' => $requestUri]),
            ));

            return;
        }

        $impersonate = $this->requestStack->getCurrentRequest()?->query->get(self::IMPERSONATE_PARAMETER);
        if (\is_string($impersonate) && '' !== $impersonate && $user instanceof User) {
            if (!$this->resolveImpersonation($event, $user, $impersonate, $requestUri)) {
                return; // a response (confirmation page) has been set
            }
        }

        $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);
    }

    /**
     * @return bool true when the code can be issued for the target user
     */
    private function resolveImpersonation(AuthorizationRequestResolveEvent $event, User $admin, string $targetId, string $requestUri): bool
    {
        $target = $this->users->findOneBy(['externalUserId' => $targetId]);
        if (null === $target) {
            throw self::deny($event, 'Unknown user to impersonate.');
        }

        $denial = $this->impersonationPolicy->denialReason($admin, $target);
        if (null !== $denial) {
            throw self::deny($event, $denial);
        }

        $sessionId = $this->confirmations->consume($requestUri);
        $session = null !== $sessionId ? $this->impersonations->find($sessionId) : null;

        if (null === $session
            || $session->getAdmin()->getId() !== $admin->getId()
            || $session->getTarget()->getId() !== $target->getId()
            || !$session->isActive($this->clock->now())) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('app_impersonation_confirm', ['return_to' => $requestUri]),
            ));

            return false;
        }

        // From here on the code is the target's (sub = target), tied to the session (act = admin).
        $event->setUser($target);
        $this->impersonationContext->set($session);

        return true;
    }

    private function postponedInThisSession(User $user): bool
    {
        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;

        return true === $session?->get(self::POSTPONED_SESSION_KEY) && $this->twoFactorPolicy->canPostpone($user);
    }

    /**
     * access_denied redirected to the client, carrying its state so it can match the response.
     */
    private static function deny(AuthorizationRequestResolveEvent $event, string $hint): OAuthServerException
    {
        $exception = OAuthServerException::accessDenied($hint, $event->getRedirectUri());
        if (null !== $event->getState()) {
            $exception->setPayload($exception->getPayload() + ['state' => $event->getState()]);
        }

        return $exception;
    }
}
