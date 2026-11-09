<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountManager;
use App\Account\PasswordLinks;
use App\Account\PasswordRules;
use App\I18n\Text;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public pages: "Forgot your password?" and the page a password link opens.
 */
#[Route('/password')]
final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordLinks $links,
        private readonly AccountManager $accounts,
        private readonly RateLimiterFactoryInterface $passwordLinkLimiter,
        private readonly RateLimiterFactoryInterface $passwordLinkIpLimiter,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * Same answer whether the account exists or not: the page never tells who has an account.
     */
    #[Route('/forgot', name: 'app_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        $sent = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('password_forgot', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $identifier = trim($request->request->getString('identifier'));
            $user = '' === $identifier ? null : ($this->users->findOneByUsername($identifier) ?? $this->users->findOneBy(['email' => mb_strtolower($identifier)]));

            $allowed = $this->passwordLinkIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()
                && $this->passwordLinkLimiter->create(mb_strtolower($identifier))->consume()->isAccepted();
            if ($allowed && null !== $user && $user->isEnabled() && null !== $user->getEmail()) {
                try {
                    $this->links->send($user, PasswordLinks::RESET);
                } catch (TransportExceptionInterface $e) {
                    $this->auditLogger->error('password_link.send_failed', ['username' => $user->getUsername(), 'error' => $e->getMessage()]);
                }
            }
            $sent = true;
        }

        return SecurityController::harden($this->render('password/forgot.html.twig', ['sent' => $sent]));
    }

    #[Route('/set/{secret}', name: 'app_password_set', requirements: ['secret' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function set(Request $request, string $secret): Response
    {
        $user = $this->links->userFor($secret);
        $error = null;

        if (null !== $user && $request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('password_set', $request->request->getString('_csrf_token'))) {
                $error = new Text('common.session_expired_please_try_again');
            } else {
                $password = $request->request->getString('password');
                $error = PasswordRules::problem($password, $request->request->getString('confirmation'));
                if (null === $error) {
                    $this->accounts->changePassword($user, $password);
                    $this->addFlash('success', new Text('password.password_is_saved_sign_in'));

                    return $this->redirectToRoute('app_login');
                }
            }
        }

        $response = $this->render('password/set.html.twig', [
            'user' => $user,
            'error' => $error,
            'min_length' => PasswordRules::MIN_LENGTH,
        ], new Response(status: null === $user ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
        // The secret is in the address: never send it to another site.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return SecurityController::harden($response);
    }
}
