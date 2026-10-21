<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Event\AccountEvent;
use App\OAuth\AuthorizationRequestListener;
use App\TwoFactor\TotpEnrollment;
use App\TwoFactor\TwoFactorPolicy;
use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Minimal self-service: TOTP enrollment (or postponing it during the grace period), backup codes,
 * disabling 2FA when the policy allows it.
 */
#[Route('/account')]
final class AccountController extends AbstractController
{
    private const SESSION_PENDING_SECRET = 'totp_pending_secret';
    private const SESSION_BACKUP_CODES = 'totp_backup_codes';

    public function __construct(
        private readonly TotpEnrollment $enrollment,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_account', methods: ['GET'])]
    public function index(): Response
    {
        return SecurityController::harden($this->render('account/index.html.twig', [
            'user' => $user = $this->user(),
            'can_disable' => $this->twoFactorPolicy->canDisable($user),
        ]));
    }

    #[Route('/2fa/setup', name: 'app_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $user = $this->user();
        $returnTo = self::safeReturnTo($request->query->getString('return_to'));

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirect($returnTo ?? $this->generateUrl('app_account'));
        }

        $session = $request->getSession();
        $secret = $session->get(self::SESSION_PENDING_SECRET);
        if (!\is_string($secret)) {
            $secret = $this->enrollment->generateSecret();
            $session->set(self::SESSION_PENDING_SECRET, $secret);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp_setup', $request->request->getString('_csrf_token'))) {
                $error = 'Your session expired, please try again.';
            } elseif ($this->enrollment->isValidCode($secret, $request->request->getString('code'))) {
                $session->remove(self::SESSION_PENDING_SECRET);
                $session->set(self::SESSION_BACKUP_CODES, $this->enrollment->enable($user, $secret));

                return $this->redirectToRoute('app_2fa_backup_codes', array_filter(['return_to' => $returnTo]));
            } else {
                $error = 'This code is not valid. Check the time on your phone and try again.';
            }
        }

        $uri = $this->enrollment->provisioningUri($user, $secret);

        return SecurityController::harden($this->render('account/2fa_setup.html.twig', [
            'qr_code' => $this->enrollment->qrCodeDataUri($uri),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'error' => $error,
            'can_postpone' => $this->twoFactorPolicy->canPostpone($user),
            'deadline' => $this->twoFactorPolicy->isDeadlinePassed() ? null : $this->twoFactorPolicy->deadline(),
            'days_left' => $this->twoFactorPolicy->daysLeft(),
            'return_to' => $returnTo,
        ]));
    }

    /**
     * "Later" on the enrollment page, during the grace period only. Asked again at the next login.
     */
    #[Route('/2fa/postpone', name: 'app_2fa_postpone', methods: ['POST'])]
    #[IsCsrfTokenValid('totp_postpone', tokenKey: '_csrf_token')]
    public function postpone(Request $request): Response
    {
        $user = $this->user();
        $returnTo = self::safeReturnTo($request->query->getString('return_to')) ?? $this->generateUrl('app_account');

        if (!$this->twoFactorPolicy->canPostpone($user)) {
            $this->addFlash('error', 'Two-factor authentication is now required for your account.');

            return $this->redirectToRoute('app_2fa_setup', ['return_to' => $returnTo]);
        }

        $user->postponeTwoFactor($this->clock->now());
        $this->em->flush();
        $request->getSession()->set(AuthorizationRequestListener::POSTPONED_SESSION_KEY, true);

        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_TWO_FACTOR_POSTPONED, $user, [
            'postpone_count' => $user->getTwoFactorPostponeCount(),
            'deadline' => $this->twoFactorPolicy->deadline()?->format(\DATE_ATOM),
        ]));

        return $this->redirect($returnTo);
    }

    #[Route('/2fa/backup-codes', name: 'app_2fa_backup_codes', methods: ['GET'])]
    public function backupCodes(Request $request): Response
    {
        // Shown once: the codes leave the session as soon as they are displayed.
        $codes = $request->getSession()->remove(self::SESSION_BACKUP_CODES);
        if (!\is_array($codes)) {
            return $this->redirectToRoute('app_account');
        }

        return SecurityController::harden($this->render('account/backup_codes.html.twig', [
            'codes' => $codes,
            'continue_to' => self::safeReturnTo($request->query->getString('return_to')) ?? $this->generateUrl('app_account'),
        ]));
    }

    #[Route('/2fa/backup-codes', name: 'app_2fa_backup_codes_regenerate', methods: ['POST'])]
    #[IsCsrfTokenValid('totp_manage', tokenKey: '_csrf_token')]
    public function regenerateBackupCodes(Request $request): Response
    {
        $user = $this->user();
        if (!$this->checkCurrentCode($user, $request)) {
            return $this->redirectToRoute('app_account');
        }

        $request->getSession()->set(self::SESSION_BACKUP_CODES, $this->enrollment->regenerateBackupCodes($user));

        return $this->redirectToRoute('app_2fa_backup_codes');
    }

    #[Route('/2fa/disable', name: 'app_2fa_disable', methods: ['POST'])]
    #[IsCsrfTokenValid('totp_manage', tokenKey: '_csrf_token')]
    public function disable(Request $request): Response
    {
        $user = $this->user();
        if (!$this->twoFactorPolicy->canDisable($user)) {
            $this->addFlash('error', 'Two-factor authentication is mandatory and cannot be disabled.');
        } elseif ($this->checkCurrentCode($user, $request)) {
            $this->enrollment->disable($user);
            $this->addFlash('success', 'Two-factor authentication disabled.');
        }

        return $this->redirectToRoute('app_account');
    }

    /**
     * Sensitive 2FA changes require a fresh code, not only the session.
     */
    private function checkCurrentCode(User $user, Request $request): bool
    {
        $secret = $user->getTotpAuthenticationConfiguration()?->getSecret();
        if (null === $secret || !$this->enrollment->isValidCode($secret, $request->request->getString('code'))) {
            $this->addFlash('error', 'Invalid authentication code.');

            return false;
        }

        return true;
    }

    /**
     * Only same-origin relative paths: prevents open redirects through return_to.
     */
    public static function safeReturnTo(string $value): ?string
    {
        if ('' === $value || '/' !== $value[0] || str_starts_with($value, '//') || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F]/', $value)) {
            return null;
        }

        return $value;
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
