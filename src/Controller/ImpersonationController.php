<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Impersonation\ImpersonationConfirmations;
use App\Impersonation\ImpersonationManager;
use App\Impersonation\ImpersonationPolicy;
use App\OAuth\AuthorizationRequestListener;
use App\Repository\UserRepository;
use App\TwoFactor\TotpEnrollment;
use GuillaumeTissier\ResourceGuard\Dpop\DpopProofValidator;
use GuillaumeTissier\ResourceGuard\Guard\AuthenticationFailed;
use GuillaumeTissier\ResourceGuard\Guard\ResourceGuard;
use GuillaumeTissier\ResourceGuard\Token\AccessTokenValidator;
use GuillaumeTissier\ResourceGuard\Token\StaticJwksProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImpersonationController extends AbstractController
{
    private const REASON_MIN_LENGTH = 10;
    private const REASON_MAX_LENGTH = 500;

    public function __construct(
        private readonly ImpersonationPolicy $policy,
        private readonly ImpersonationManager $impersonations,
        private readonly ImpersonationConfirmations $confirmations,
        private readonly TotpEnrollment $totp,
        private readonly UserRepository $users,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Step-up page between /authorize?impersonate=… and the code: the administrator states a reason
     * and proves possession of the second factor again, whatever the age of the session.
     */
    #[Route('/impersonation/confirm', name: 'app_impersonation_confirm', methods: ['GET', 'POST'])]
    public function confirm(Request $request): Response
    {
        $admin = $this->getUser();
        if (!$admin instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $returnTo = AccountController::safeReturnTo($request->query->getString('return_to'));
        if (null === $returnTo || !str_starts_with($returnTo, '/authorize?')) {
            throw $this->createNotFoundException();
        }
        parse_str((string) parse_url($returnTo, \PHP_URL_QUERY), $authorizeQuery);
        $target = \is_string($authorizeQuery[AuthorizationRequestListener::IMPERSONATE_PARAMETER] ?? null)
            ? $this->users->findOneBy(['externalUserId' => $authorizeQuery[AuthorizationRequestListener::IMPERSONATE_PARAMETER]])
            : null;
        if (null === $target) {
            throw $this->createNotFoundException();
        }

        $denial = $this->policy->denialReason($admin, $target);
        if (null !== $denial) {
            throw $this->createAccessDeniedException($denial);
        }

        $errors = [];
        $reason = trim($request->request->getString('reason'));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('impersonation_confirm', $request->request->getString('_csrf_token'))) {
                $errors[] = 'Your session expired, please try again.';
            }
            if (mb_strlen($reason) < self::REASON_MIN_LENGTH || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
                $errors[] = \sprintf('Please give a reason (%d to %d characters).', self::REASON_MIN_LENGTH, self::REASON_MAX_LENGTH);
            }
            if (!$this->isFreshTotpCode($admin, $request->request->getString('code'))) {
                $errors[] = 'This authentication code is not valid.';
            }

            if ([] === $errors) {
                $session = $this->impersonations->start($admin, $target, $reason);
                $this->confirmations->remember($returnTo, $session->getId());

                return $this->redirect($returnTo);
            }
        }

        return SecurityController::harden($this->render('impersonation/confirm.html.twig', [
            'target' => $target,
            'mode' => $this->policy->modeFor($admin, $target),
            'ttl_minutes' => (int) round((new \DateTimeImmutable('@0'))->add($this->policy->sessionTtl())->getTimestamp() / 60),
            'reason' => $reason,
            'errors' => $errors,
        ]));
    }

    /**
     * Ends the impersonation the presented token belongs to. Called by the client application
     * ("back to my account") with the impersonation access token and a DPoP proof.
     */
    #[Route('/impersonation/end', name: 'app_impersonation_end', methods: ['POST'])]
    public function end(
        Request $request,
        DpopProofValidator $dpopValidator,
        #[Autowire('%app.issuer%')] string $issuer,
        #[Autowire('%env(resolve:OAUTH_PUBLIC_KEY)%')] string $publicKeyPath,
    ): Response {
        // A write by nature: accepted from read-only impersonation tokens too.
        $guard = new ResourceGuard(
            new AccessTokenValidator(StaticJwksProvider::fromPublicKeyFile($publicKeyPath)),
            $dpopValidator,
            requireDpop: false,
            enforceReadOnlyImpersonation: false,
        );

        try {
            $principal = $guard->authenticate(
                'POST',
                rtrim($issuer, '/').'/impersonation/end',
                $request->headers->get('Authorization'),
                array_values(array_filter($request->headers->all('dpop'), \is_string(...))),
            );
        } catch (AuthenticationFailed $e) {
            return new JsonResponse($e->toArray(), $e->statusCode, ['WWW-Authenticate' => $e->wwwAuthenticate()]);
        }

        $id = $principal->token->impersonationId();
        $session = null !== $id ? $this->impersonations->find($id) : null;
        if (null === $session) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'Not an impersonation token'], 400);
        }

        $this->impersonations->end($session, ImpersonationSession::END_BY_ADMIN);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Valid TOTP code, never accepted twice for an impersonation (each confirmation needs its own code).
     */
    private function isFreshTotpCode(User $admin, string $code): bool
    {
        $code = trim($code);
        $secret = $admin->getTotpAuthenticationConfiguration()?->getSecret();
        if ('' === $code || null === $secret) {
            return false;
        }

        $item = $this->cache->getItem('impersonation_totp_'.hash('sha256', $admin->getId().'|'.$code));
        if ($item->isHit() || !$this->totp->isValidCode($secret, $code)) {
            return false;
        }

        $item->set(true)->expiresAfter(120);
        $this->cache->save($item);

        return true;
    }
}
