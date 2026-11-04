<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClientPolicy;
use App\Entity\User;
use App\Settings\Duration;
use App\Settings\SystemSettings;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * System settings: token security (DPoP, PKCE), token lifetimes and the two-factor policy, plus the DPoP
 * policy of each application. Changed by the super-administrator; platform administrators see them.
 */
#[Route('/admin/settings')]
final class AdminSettingsController extends AbstractAdminController
{
    /** What each lifetime is, for the page. */
    private const LIFETIMES = [
        SystemSettings::ACCESS_TOKEN_TTL => ['Access token', 'Sent with every request to an API. Short: a stolen token stops working soon. The applications renew it with the refresh token, without asking the user.'],
        SystemSettings::REFRESH_TOKEN_TTL => ['Refresh token', 'How long a user stays signed in to an application without typing his password again (each renewal gives a new one, valid this long).'],
        SystemSettings::IMPERSONATION_TTL => ['Impersonation session', 'How long an administrator may act on behalf of a user before signing in again.'],
        SystemSettings::IMPERSONATION_ACCESS_TOKEN_TTL => ['Access token during an impersonation', 'Kept short, so that ending an impersonation takes effect quickly in the APIs.'],
    ];

    public function __construct(
        private readonly SystemSettings $settings,
        private readonly ClientManagerInterface $clients,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_settings', methods: ['GET'])]
    public function show(): Response
    {
        $actor = $this->actor();
        if (!$this->policy->isPlatformStaff($actor)) {
            throw $this->createAccessDeniedException();
        }

        $rows = [];
        foreach (array_keys($this->settings->defaults()) as $name) {
            $rows[$name] = $this->settings->row($name);
        }
        $lifetimes = [];
        foreach (self::LIFETIMES as $name => [$label, $help]) {
            [$amount, $unit] = Duration::split($this->settings->lifetimeIso($name));
            [$min, $max] = SystemSettings::LIFETIME_BOUNDS[$name];
            $lifetimes[$name] = [
                'field' => str_replace('.', '_', $name),
                'label' => $label,
                'help' => $help,
                'amount' => $amount,
                'unit' => $unit,
                'default' => Duration::human($this->settings->defaults()[$name]),
                'range' => \sprintf('from %s to %s', Duration::human('PT'.intdiv($min, 60).'M'), Duration::human('PT'.intdiv($max, 60).'M')),
            ];
        }

        $policies = [];
        foreach ($this->em->getRepository(ClientPolicy::class)->findAll() as $policy) {
            $policies[$policy->getClient()->getIdentifier()] = $policy;
        }

        return $this->page('admin/settings.html.twig', [
            'can_edit' => $this->policy->isSuperAdmin($actor),
            'dpop_required' => $this->settings->dpopRequiredByDefault(),
            'pkce_confidential' => $this->settings->pkceForConfidentialClients(),
            'two_factor_mode' => $this->settings->twoFactorMode(),
            'two_factor_deadline' => $this->settings->twoFactorDeadline(),
            'rows' => $rows,
            'defaults' => $this->settings->defaults(),
            'lifetimes' => $lifetimes,
            'units' => array_keys(Duration::UNITS),
            'clients' => $this->clients->list(null),
            'policies' => $policies,
        ]);
    }

    #[Route('', name: 'app_admin_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $actor = $this->superAdmin($request);

        if ($request->request->getBoolean('use_defaults')) {
            $this->settings->save(array_fill_keys(array_keys($this->settings->defaults()), null), $actor);
            $this->addFlash('success', 'Back to the defaults of the server configuration (.env.docker).');

            return $this->redirectToRoute('app_admin_settings');
        }

        $mode = $request->request->getString('two_factor_mode');
        $deadline = trim($request->request->getString('two_factor_deadline'));
        $date = '' === $deadline ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);
        $error = match (true) {
            !\in_array($mode, SystemSettings::TWO_FACTOR_MODES, true) => 'Choose a two-factor mode.',
            false === $date || (null !== $date && $date->format('Y-m-d') !== $deadline) => 'The deadline must be a date such as 2026-12-31.',
            default => null,
        };
        $lifetimes = [];
        foreach (array_keys(self::LIFETIMES) as $name) {
            [$lifetimes[$name], $problem] = $this->lifetime($request, $name);
            $error ??= $problem;
        }
        if (null === $error) {
            $seconds = array_map(static fn (string $iso): int => Duration::seconds($iso), $lifetimes);
            $error = match (true) {
                $seconds[SystemSettings::REFRESH_TOKEN_TTL] <= $seconds[SystemSettings::ACCESS_TOKEN_TTL] => 'The refresh token must live longer than the access token.',
                $seconds[SystemSettings::IMPERSONATION_ACCESS_TOKEN_TTL] > $seconds[SystemSettings::IMPERSONATION_TTL] => 'During an impersonation, the access token cannot live longer than the session.',
                default => null,
            };
        }
        if (null !== $error) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_admin_settings');
        }

        $this->settings->save($lifetimes + [
            SystemSettings::DPOP_REQUIRED => $request->request->getBoolean('dpop_required') ? '1' : '0',
            SystemSettings::PKCE_CONFIDENTIAL => $request->request->getBoolean('pkce_confidential') ? '1' : '0',
            SystemSettings::TWO_FACTOR_MODE => $mode,
            SystemSettings::TWO_FACTOR_DEADLINE => $deadline,
        ], $actor);

        $this->addFlash('success', 'Settings saved. They apply at once, to the next sign-ins and token requests.');

        return $this->redirectToRoute('app_admin_settings');
    }

    /**
     * DPoP policy of one application: the system default, required, or not required (Bearer tokens).
     */
    #[Route('/clients/{identifier}', name: 'app_admin_settings_client', methods: ['POST'])]
    public function client(Request $request, string $identifier): Response
    {
        $actor = $this->superAdmin($request);
        $client = $this->clients->find($identifier);
        if (!$client instanceof Client) {
            throw $this->createNotFoundException();
        }

        $requireDpop = match ($request->request->getString('dpop')) {
            'required' => true,
            'bearer' => false,
            default => null,
        };
        $policy = $this->em->getRepository(ClientPolicy::class)->findOneBy(['client' => $client]);
        if (null === $policy) {
            $policy = new ClientPolicy($client);
            $this->em->persist($policy);
        }
        $policy->setRequireDpop($requireDpop);
        $this->em->flush();

        $this->auditLogger->info('client.dpop_policy_changed', [
            'client' => $identifier,
            'require_dpop' => null === $requireDpop ? 'default' : ($requireDpop ? 'yes' : 'no'),
            'actor' => $actor->getUsername(),
            'actor_id' => $actor->getExternalUserId(),
        ]);
        $this->addFlash('success', \sprintf('DPoP policy of %s saved.', $client->getName()));

        return $this->redirectToRoute('app_admin_settings');
    }

    /**
     * The lifetime typed in the form, as an ISO 8601 duration, and why it is refused (null: accepted).
     *
     * @return array{string, string|null}
     */
    private function lifetime(Request $request, string $name): array
    {
        $field = str_replace('.', '_', $name);
        $amount = $request->request->getString($field.'_amount');
        $unit = $request->request->getString($field.'_unit');
        if (1 !== preg_match('/^[1-9][0-9]{0,5}$/', $amount) || !\array_key_exists($unit, Duration::UNITS)) {
            return ['', \sprintf('%s: give a whole number of minutes, hours or days.', self::LIFETIMES[$name][0])];
        }
        $iso = Duration::toIso((int) $amount, $unit);
        [$min, $max] = SystemSettings::LIFETIME_BOUNDS[$name];
        $seconds = Duration::seconds($iso);
        if ($seconds < $min || $seconds > $max) {
            return [$iso, \sprintf('%s: between %s and %s.', self::LIFETIMES[$name][0], Duration::human('PT'.intdiv($min, 60).'M'), Duration::human('PT'.intdiv($max, 60).'M'))];
        }

        return [$iso, null];
    }

    private function superAdmin(Request $request): User
    {
        $actor = $this->actor();
        if (!$this->policy->isSuperAdmin($actor)) {
            throw $this->createAccessDeniedException('Only the super-administrator changes the system settings.');
        }
        $this->checkCsrf($request, 'admin_settings');

        return $actor;
    }
}
