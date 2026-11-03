<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClientPolicy;
use App\Entity\User;
use App\Settings\SystemSettings;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * System settings: token security (DPoP, PKCE) and the two-factor policy, plus the DPoP policy of each
 * application. Changed by the super-administrator; platform administrators see them.
 */
#[Route('/admin/settings')]
final class AdminSettingsController extends AbstractAdminController
{
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
        foreach ([SystemSettings::DPOP_REQUIRED, SystemSettings::PKCE_CONFIDENTIAL, SystemSettings::TWO_FACTOR_MODE, SystemSettings::TWO_FACTOR_DEADLINE] as $name) {
            $rows[$name] = $this->settings->row($name);
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
        if (null !== $error) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_admin_settings');
        }

        $this->settings->save([
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
