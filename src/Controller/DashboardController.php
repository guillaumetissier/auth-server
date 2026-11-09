<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessPolicy;
use App\Entity\Domain;
use App\Entity\User;
use App\I18n\Text;
use App\Repository\UserRepository;
use App\TwoFactor\TwoFactorPolicy;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * First page after sign-in: notifications for everyone; for administrators, figures about the users
 * they administer, the last sign-ins and the last domains created.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly UserRepository $users,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute(null === $this->getUser() ? 'app_login' : 'app_dashboard');
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $parameters = [
            'notifications' => $this->notifications($user),
            'admin' => null,
        ];

        if ($this->policy->canAdminister($user)) {
            $domains = $this->policy->visibleDomains($user);
            $scope = $this->policy->isPlatformStaff($user) ? null : array_values(array_filter(
                $domains,
                fn (Domain $d): bool => $this->policy->levelOn($user, '@access', $d)->includes(AccessLevel::Read),
            ));
            $recentDomains = $domains;
            usort($recentDomains, static fn (Domain $a, Domain $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());
            $recentDomains = \array_slice($recentDomains, 0, 6);

            $parameters['admin'] = [
                'stats' => $this->users->stats($scope, $this->clock->now()),
                'scope' => null === $scope ? new Text('dashboard.scope_all_users') : new Text('dashboard.scope_domain_users'),
                'recent_logins' => $this->users->recentLogins($scope),
                'recent_domains' => $recentDomains,
                'members' => $this->users->memberCounts($recentDomains),
                'domain_count' => \count($domains),
                'two_factor_mode' => $this->twoFactorPolicy->mode(),
                'two_factor_deadline' => $this->twoFactorPolicy->deadline(),
            ];
        }

        return SecurityController::harden($this->render('dashboard/index.html.twig', $parameters));
    }

    /**
     * @return list<array{level: 'warn'|'info', text: Text, link?: string, action?: Text}>
     */
    private function notifications(User $user): array
    {
        $notifications = [];
        if (!$user->isTotpAuthenticationEnabled()) {
            $deadline = $this->twoFactorPolicy->deadline();
            $notifications[] = [
                'level' => 'warn',
                'text' => match (true) {
                    $user->isAdministrator() => new Text('dashboard.two_factor_authentication_is_not_enabled_administrators'),
                    TwoFactorPolicy::MODE_GRACE === $this->twoFactorPolicy->mode() && null !== $deadline => new Text('dashboard.two_factor_authentication_is_not_enabled_it_becomes', ['{deadline}' => $deadline]),
                    default => new Text('dashboard.two_factor_authentication_is_not_enabled_it_protects'),
                },
                'link' => $this->generateUrl('app_2fa_setup'),
                'action' => new Text('dashboard.set_it_up'),
            ];
        } elseif ($user->countBackupCodes() < 3) {
            $notifications[] = [
                'level' => 'warn',
                'text' => new Text('dashboard.only_count_left_generate_new', ['{count}' => $user->countBackupCodes()]),
                'link' => $this->generateUrl('app_account'),
                'action' => new Text('common.my_profile'),
            ];
        }
        if (null === $user->getEmail()) {
            $notifications[] = [
                'level' => 'info',
                'text' => new Text('dashboard.add_e_mail_address_to'),
                'link' => $this->generateUrl('app_account'),
                'action' => new Text('dashboard.add_it'),
            ];
        }

        return $notifications;
    }
}
