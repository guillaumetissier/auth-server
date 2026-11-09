<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClientPolicy;
use App\Entity\User;
use App\I18n\Locales;
use App\I18n\Text;
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
    /**
     * What each lifetime is, for the page: label and help.
     *
     * @return array<string, array{Text, Text}>
     */
    private static function lifetimes(): array
    {
        return [
            SystemSettings::ACCESS_TOKEN_TTL => [new Text('admin.settings.access_token'), new Text('admin.settings.sent_with_every_request_to')],
            SystemSettings::REFRESH_TOKEN_TTL => [new Text('admin.settings.refresh_token'), new Text('admin.settings.how_long_user_stays_signed')],
            SystemSettings::IMPERSONATION_TTL => [new Text('admin.settings.impersonation_session'), new Text('admin.settings.how_long_administrator_may_act')],
            SystemSettings::IMPERSONATION_ACCESS_TOKEN_TTL => [new Text('admin.settings.access_token_during_impersonation'), new Text('admin.settings.kept_short_so_that_ending')],
        ];
    }

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
        foreach (self::lifetimes() as $name => [$label, $help]) {
            [$amount, $unit] = Duration::split($this->settings->lifetimeIso($name));
            [$min, $max] = SystemSettings::LIFETIME_BOUNDS[$name];
            $lifetimes[$name] = [
                'field' => str_replace('.', '_', $name),
                'label' => $label,
                'help' => $help,
                'amount' => $amount,
                'unit' => $unit,
                'default' => Duration::text($this->settings->defaults()[$name]),
                'range' => new Text('admin.settings.from_min_to_max', ['{min}' => Duration::text('PT'.intdiv($min, 60).'M'), '{max}' => Duration::text('PT'.intdiv($max, 60).'M')]),
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
            'default_locale' => $this->settings->defaultLocale(),
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
            $this->addFlash('success', new Text('admin.settings.back_to_defaults_server_configuration'));

            return $this->redirectToRoute('app_admin_settings');
        }

        $mode = $request->request->getString('two_factor_mode');
        $deadline = trim($request->request->getString('two_factor_deadline'));
        $date = '' === $deadline ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);
        $locale = $request->request->getString('default_locale', $this->settings->defaultLocale());
        $error = match (true) {
            !\in_array($mode, SystemSettings::TWO_FACTOR_MODES, true) => new Text('admin.settings.choose_two_factor_mode'),
            false === $date || (null !== $date && $date->format('Y-m-d') !== $deadline) => new Text('admin.settings.deadline_must_be_date_such'),
            !Locales::isEnabled($locale) => new Text('admin.settings.choose_language'),
            default => null,
        };
        $lifetimes = [];
        foreach (array_keys(self::lifetimes()) as $name) {
            [$lifetimes[$name], $problem] = $this->lifetime($request, $name);
            $error ??= $problem;
        }
        if (null === $error) {
            $seconds = array_map(static fn (string $iso): int => Duration::seconds($iso), $lifetimes);
            $error = match (true) {
                $seconds[SystemSettings::REFRESH_TOKEN_TTL] <= $seconds[SystemSettings::ACCESS_TOKEN_TTL] => new Text('admin.settings.refresh_token_must_live_longer'),
                $seconds[SystemSettings::IMPERSONATION_ACCESS_TOKEN_TTL] > $seconds[SystemSettings::IMPERSONATION_TTL] => new Text('admin.settings.during_impersonation_access_token_cannot'),
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
            SystemSettings::DEFAULT_LOCALE => $locale,
        ], $actor);

        $this->addFlash('success', new Text('admin.settings.settings_saved_they_apply_at'));

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
        $this->addFlash('success', new Text('admin.settings.dpop_policy_name_saved', ['{name}' => $client->getName()]));

        return $this->redirectToRoute('app_admin_settings');
    }

    /**
     * The lifetime typed in the form, as an ISO 8601 duration, and why it is refused (null: accepted).
     *
     * @return array{string, Text|null}
     */
    private function lifetime(Request $request, string $name): array
    {
        $field = str_replace('.', '_', $name);
        $amount = $request->request->getString($field.'_amount');
        $unit = $request->request->getString($field.'_unit');
        if (1 !== preg_match('/^[1-9][0-9]{0,5}$/', $amount) || !\array_key_exists($unit, Duration::UNITS)) {
            return ['', new Text('admin.settings.lifetime_give_whole_number_minutes', ['{lifetime}' => self::lifetimes()[$name][0]])];
        }
        $iso = Duration::toIso((int) $amount, $unit);
        [$min, $max] = SystemSettings::LIFETIME_BOUNDS[$name];
        $seconds = Duration::seconds($iso);
        if ($seconds < $min || $seconds > $max) {
            return [$iso, new Text('admin.settings.lifetime_between_min_and_max', [
                '{lifetime}' => self::lifetimes()[$name][0],
                '{min}' => Duration::text('PT'.intdiv($min, 60).'M'),
                '{max}' => Duration::text('PT'.intdiv($max, 60).'M'),
            ])];
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
