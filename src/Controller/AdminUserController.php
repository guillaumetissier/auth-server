<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessManager;
use App\Access\AccessPreview;
use App\Access\AccessResolver;
use App\Account\AccountManager;
use App\Account\PasswordLinks;
use App\Entity\AccessGrant;
use App\Entity\ClientPolicy;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Event\AccountEvent;
use App\Impersonation\ImpersonationPolicy;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Users: list, creation, account (identity, password link, 2FA reset, status, deletion), rights and
 * platform role. Decided by AccessPolicy: the super-administrator manages everybody; a holder of.
 *
 * @access:w on a domain manages its users, never beyond his own rights; ROLE_ADMIN only looks.
 */
#[Route('/admin/users')]
final class AdminUserController extends AbstractAdminController
{
    public function __construct(
        private readonly AccessManager $manager,
        private readonly AccessResolver $resolver,
        private readonly AccessPreview $preview,
        private readonly AccountManager $accounts,
        private readonly PasswordLinks $passwordLinks,
        private readonly ImpersonationPolicy $impersonation,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function users(Request $request): Response
    {
        $actor = $this->actor();
        $query = trim($request->query->getString('q'));

        return $this->page('admin/users.html.twig', [
            'query' => $query,
            'users' => $this->users->search($query, $this->scope($actor)),
            'can_create' => $this->policy->canCreateUsers($actor),
            'now' => $this->clock->now(),
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->actor();
        if (!$this->policy->canCreateUsers($actor)) {
            throw $this->createAccessDeniedException();
        }
        $domains = $this->policy->userDomainsFor($actor);
        $values = [
            'username' => trim($request->request->getString('username')),
            'external_user_id' => trim($request->request->getString('external_user_id')),
            'email' => trim($request->request->getString('email')),
            'domain' => $request->request->getString('domain', $request->query->getString('domain')),
        ];

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'admin_user');
            $domain = '' === $values['domain'] ? null : $this->findDomain($values['domain']);
            $error = match (true) {
                '' === $values['external_user_id'] || mb_strlen($values['external_user_id']) > 64 => 'Give the id of the user in your application (1 to 64 characters).',
                '' === $values['email'] => 'Give an e-mail address: the invitation to choose a password is sent there.',
                null === $domain && !$this->policy->isSuperAdmin($actor) => 'Choose the domain of the user.',
                null !== $domain && !\in_array($domain, $domains, true) => 'You cannot create users in this domain.',
                default => $this->accounts->identityProblem($values['username'], $values['email']),
            };
            if (null === $error) {
                try {
                    $user = $this->accounts->create($values['username'], $values['external_user_id'], $values['email'], $domain, $actor);
                    $this->sendLink($user, PasswordLinks::INVITATION, $actor, 'Account created. An invitation to choose a password was sent to %s.');

                    return $this->redirectToRoute('app_admin_user', ['id' => $user->getId()]);
                } catch (\InvalidArgumentException $e) {
                    $error = $e->getMessage();
                }
            }
            $this->addFlash('error', $error);
        }

        return $this->page('admin/user_new.html.twig', [
            'values' => $values,
            'domains' => $domains,
            'can_choose_none' => $this->policy->isSuperAdmin($actor),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_user', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function user(Request $request, int $id): Response
    {
        $actor = $this->actor();
        $target = $this->target($id);
        if (!$this->policy->canViewRightsOf($actor, $target)) {
            throw $this->createAccessDeniedException();
        }

        $previewDomain = $this->domains->findByCode($request->query->getString('preview')) ?? $target->getDomain();
        $grantable = array_values(array_filter(
            null === $target->getDomain() ? $this->domains->subtree() : $this->domains->subtree($target->getDomain()),
            fn (Domain $d): bool => $this->policy->isSuperAdmin($actor) || $this->policy->levelOn($actor, '@access', $d)->includes(AccessLevel::Write),
        ));

        $impersonationDenial = $this->impersonation->denialReason($actor, $target);
        $entryPoints = [];
        if (null === $impersonationDenial) {
            foreach ($this->em->getRepository(ClientPolicy::class)->findAll() as $clientPolicy) {
                if (null !== $uri = $clientPolicy->impersonationUriFor($target)) {
                    $entryPoints[] = ['name' => $clientPolicy->getClient()->getName(), 'uri' => $uri];
                }
            }
        }

        return $this->page('admin/user.html.twig', [
            'target' => $target,
            'now' => $this->clock->now(),
            'account_denial' => $this->policy->accountChangeDenial($actor, $target),
            'grants' => $this->resolver->grantsOf($target),
            'owned' => $this->domains->ownedBy($target),
            'default_expression' => $this->resolver->defaultExpression()->isEmpty() ? null : $this->resolver->defaultExpression()->toString(),
            'change_denial' => $this->policy->rightsChangeDenial($actor, $target),
            'grantable_domains' => $grantable,
            'preview_domain' => $previewDomain,
            'preview' => null !== $previewDomain ? $this->preview->rows($target, $previewDomain) : [],
            'preview_domains' => null === $target->getDomain() ? $this->domains->subtree() : $this->domains->subtree($target->getDomain()),
            'impersonation_mode' => null === $impersonationDenial ? $this->impersonation->modeFor($actor, $target) : null,
            'impersonation_entry_points' => $entryPoints,
            'can_change_roles' => $this->policy->isSuperAdmin($actor) && !$target->hasRole(User::ROLE_SUPER_ADMIN),
            'resource_types' => $this->em->getRepository(ResourceType::class)->findBy([], ['code' => 'ASC']),
            'reserved_types' => ResourceType::RESERVED,
        ]);
    }

    #[Route('/{id}/identity', name: 'app_admin_user_identity', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function identity(Request $request, int $id): Response
    {
        [$actor, $target] = $this->managed($request, $id);
        $username = trim($request->request->getString('username'));
        $email = self::optional($request, 'email');

        $problem = $this->accounts->identityProblem($username, $email, $target);
        if (null !== $problem) {
            $this->addFlash('error', $problem);
        } else {
            $this->accounts->updateIdentity($target, $username, $email, $actor);
            $this->addFlash('success', 'Account saved.');
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }

    #[Route('/{id}/password-link', name: 'app_admin_user_password_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function passwordLink(Request $request, int $id): Response
    {
        [$actor, $target] = $this->managed($request, $id);
        if (null === $target->getEmail()) {
            $this->addFlash('error', 'This account has no e-mail address: add one first.');
        } elseif (!$target->isEnabled()) {
            $this->addFlash('error', 'This account is disabled: enable it first.');
        } else {
            $this->sendLink($target, PasswordLinks::RESET, $actor, 'A link to choose a new password was sent to %s. The current password keeps working until then.');
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }

    #[Route('/{id}/two-factor/reset', name: 'app_admin_user_2fa_reset', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resetTwoFactor(Request $request, int $id): Response
    {
        [$actor, $target] = $this->managed($request, $id);
        if ($target->isTotpAuthenticationEnabled()) {
            $this->accounts->resetTwoFactor($target, $actor);
            $this->addFlash('success', \sprintf('Two-factor authentication removed. %s sets it up again at the next sign-in.', $target->getUsername()));
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }

    #[Route('/{id}/status', name: 'app_admin_user_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Request $request, int $id): Response
    {
        [$actor, $target] = $this->managed($request, $id);
        $enabled = $request->request->getBoolean('enabled');
        $this->accounts->setEnabled($target, $enabled, $actor);
        $this->addFlash('success', $enabled
            ? \sprintf('%s can sign in again.', $target->getUsername())
            : \sprintf('%s can no longer sign in, and the applications can no longer renew his tokens.', $target->getUsername()));

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }

    #[Route('/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        [$actor, $target] = $this->managed($request, $id);
        if ($request->request->getString('confirm') !== $target->getUsername()) {
            $this->addFlash('error', \sprintf('Type the username (%s) to confirm the deletion.', $target->getUsername()));

            return $this->redirectToRoute('app_admin_user', ['id' => $id]);
        }

        $username = $target->getUsername();
        $this->accounts->delete($target, $actor);
        $this->addFlash('success', \sprintf('Account %s deleted.', $username));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/grants', name: 'app_admin_user_grant', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function grant(Request $request, int $id): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_user');
        $target = $this->target($id);
        $domain = $this->findDomain($request->request->getString('domain'));
        $includeSubdomains = $request->request->getBoolean('subdomains');

        try {
            $expression = AccessExpression::parse($request->request->getString('rights'));
        } catch (InvalidAccessExpression $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_user', ['id' => $id, 'preview' => $domain->getCode()]);
        }
        $denial = $expression->isEmpty()
            ? 'Give at least one rule, or remove the grant.'
            : $this->policy->grantDenial($actor, $target, $domain, $expression, $includeSubdomains, $this->manager->findGrant($target, $domain));

        if (null !== $denial) {
            $this->addFlash('error', $denial);
        } else {
            $this->manager->grant($target, $domain, $expression, $includeSubdomains, $actor);
            $this->addFlash('success', \sprintf('Rights on %s saved. They apply from the next token of %s.', $domain->getPath(), $target->getUsername()));
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id, 'preview' => $domain->getCode()]);
    }

    #[Route('/{id}/grants/{grant}/delete', name: 'app_admin_user_revoke', requirements: ['id' => '\d+', 'grant' => '\d+'], methods: ['POST'])]
    public function revoke(Request $request, int $id, int $grant): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_user');
        $entity = $this->em->find(AccessGrant::class, $grant);
        if (null === $entity || $entity->getUser()->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        $denial = $this->policy->revokeDenial($actor, $entity);
        if (null !== $denial) {
            $this->addFlash('error', $denial);
        } else {
            $this->manager->revoke($entity, $actor);
            $this->addFlash('success', 'Rights removed.');
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }

    #[Route('/{id}/roles', name: 'app_admin_user_roles', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function roles(Request $request, int $id): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_user');
        $target = $this->target($id);
        if (!$this->policy->isSuperAdmin($actor) || $target->hasRole(User::ROLE_SUPER_ADMIN)) {
            throw $this->createAccessDeniedException();
        }

        $request->request->getBoolean('admin') ? $target->addRole(User::ROLE_ADMIN) : $target->removeRole(User::ROLE_ADMIN);
        $this->em->flush();
        $this->dispatcher->dispatch(new AccountEvent(WebhookEvent::USER_ROLES_CHANGED, $target, [
            'roles' => implode(',', $target->getRoles()),
            'actor_id' => $actor->getExternalUserId(),
        ]));
        $this->addFlash('success', \sprintf('%s is %s platform administrator.', $target->getUsername(), $target->hasRole(User::ROLE_ADMIN) ? 'now a' : 'no longer a'));

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }

    /**
     * The actor and the target of an account change, after the CSRF and permission checks.
     *
     * @return array{User, User}
     */
    private function managed(Request $request, int $id): array
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_user');
        $target = $this->target($id);
        $denial = $this->policy->accountChangeDenial($actor, $target);
        if (null !== $denial) {
            throw $this->createAccessDeniedException($denial);
        }

        return [$actor, $target];
    }

    /**
     * @param PasswordLinks::RESET|PasswordLinks::INVITATION $purpose
     */
    private function sendLink(User $user, string $purpose, User $actor, string $success): void
    {
        try {
            $this->passwordLinks->send($user, $purpose, $actor);
            $this->addFlash('success', \sprintf($success, $user->getEmail()));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', 'The e-mail could not be sent ('.$e->getMessage().'). Check the MAILER_DSN setting, then use "Send a password link".');
        }
    }

    /**
     * Domains whose users $actor may see; null: everybody (platform staff).
     *
     * @return list<Domain>|null
     */
    private function scope(User $actor): ?array
    {
        return $this->policy->isPlatformStaff($actor) ? null : array_values(array_filter(
            $this->policy->visibleDomains($actor),
            fn (Domain $d): bool => $this->policy->levelOn($actor, '@access', $d)->includes(AccessLevel::Read),
        ));
    }
}
