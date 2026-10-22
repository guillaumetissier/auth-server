<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessManager;
use App\Access\AccessPolicy;
use App\Access\AccessPreview;
use App\Access\AccessResolver;
use App\Entity\AccessGrant;
use App\Entity\ClientPolicy;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use App\Event\AccountEvent;
use App\Impersonation\ImpersonationPolicy;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Administration of domains, users and rights. Every page and action is decided by AccessPolicy:
 * platform staff see everything (only the super-administrator changes things), domain users see and
 * change what their @access / @domain rights allow, never beyond their own rights.
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly AccessManager $manager,
        private readonly AccessResolver $resolver,
        private readonly AccessPreview $preview,
        private readonly ImpersonationPolicy $impersonation,
        private readonly DomainRepository $domains,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    #[Route('', name: 'app_admin_domains', methods: ['GET'])]
    public function domains(): Response
    {
        $actor = $this->actor();

        return $this->page('admin/domains.html.twig', [
            'domains' => $this->policy->visibleDomains($actor),
            'can_create_root' => $this->policy->canCreateSubdomain($actor, null),
        ]);
    }

    #[Route('/domains/new', name: 'app_admin_domain_new', methods: ['POST'])]
    public function createDomain(Request $request): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_domain');
        $parentCode = $request->request->getString('parent');
        $parent = '' === $parentCode ? null : $this->findDomain($parentCode);
        if (!$this->policy->canCreateSubdomain($actor, $parent)) {
            throw $this->createAccessDeniedException();
        }

        $code = strtolower(trim($request->request->getString('code')));
        if (null !== $this->domains->findByCode($code)) {
            $this->addFlash('error', \sprintf('Domain "%s" already exists.', $code));
        } else {
            try {
                $domain = $this->manager->createDomain($code, trim($request->request->getString('name')) ?: $code, $parent);
                $this->addFlash('success', \sprintf('Domain %s created.', $domain->getPath()));

                return $this->redirectToRoute('app_admin_domain', ['code' => $domain->getCode()]);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return null === $parent ? $this->redirectToRoute('app_admin_domains') : $this->redirectToRoute('app_admin_domain', ['code' => $parent->getCode()]);
    }

    #[Route('/domains/{code}', name: 'app_admin_domain', methods: ['GET'])]
    public function showDomain(string $code): Response
    {
        $actor = $this->actor();
        $domain = $this->findDomain($code);
        if (!$this->policy->canViewDomain($actor, $domain)) {
            throw $this->createAccessDeniedException();
        }

        $children = array_values(array_filter(
            $this->domains->findBy(['parent' => $domain], ['code' => 'ASC']),
            fn (Domain $d): bool => $this->policy->canViewDomain($actor, $d),
        ));
        $canSeeUsers = $this->policy->levelOn($actor, '@access', $domain)->includes(AccessLevel::Read);

        return $this->page('admin/domain.html.twig', [
            'domain' => $domain,
            'ancestors' => $this->ancestors($domain),
            'children' => $children,
            'members' => $canSeeUsers ? $this->users->membersOf($domain) : [],
            'can_see_users' => $canSeeUsers,
            'can_add_members' => $this->policy->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write),
            'can_create_subdomain' => $this->policy->canCreateSubdomain($actor, $domain),
            'can_change_owner' => $this->canChangeOwner($actor, $domain),
        ]);
    }

    #[Route('/domains/{code}/owner', name: 'app_admin_domain_owner', methods: ['POST'])]
    public function setOwner(Request $request, string $code): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_domain');
        $domain = $this->findDomain($code);

        $username = trim($request->request->getString('owner'));
        $owner = '' === $username ? null : $this->users->findOneByUsername($username);
        if ('' !== $username && null === $owner) {
            $this->addFlash('error', \sprintf('Unknown user "%s".', $username));

            return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
        }
        try {
            $expression = AccessExpression::parse($request->request->getString('owner_rights'));
        } catch (InvalidAccessExpression $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
        }

        $denial = $this->policy->ownerChangeDenial($actor, $domain, $owner, $expression);
        if (null !== $denial) {
            $this->addFlash('error', $denial);
        } else {
            $this->manager->setOwner($domain, $owner, $expression, $actor);
            $this->addFlash('success', 'Owner saved. It applies from the next token of the users concerned.');
        }

        return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
    }

    #[Route('/domains/{code}/members', name: 'app_admin_domain_member', methods: ['POST'])]
    public function addMember(Request $request, string $code): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_domain');
        $domain = $this->findDomain($code);
        $target = $this->users->findOneByUsername(trim($request->request->getString('username')));
        if (null === $target) {
            $this->addFlash('error', 'Unknown user.');

            return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
        }
        // Moving someone in requires the right to manage him where he is now, too.
        $denial = $this->policy->domainChangeDenial($actor, $target, $domain);
        if (null === $denial && null !== $target->getDomain() && !$this->policy->isSuperAdmin($actor)
            && !$this->policy->levelOn($actor, '@access', $target->getDomain())->includes(AccessLevel::Write)) {
            $denial = 'This user belongs to a domain you do not manage.';
        }
        if (null !== $denial) {
            $this->addFlash('error', $denial);
        } else {
            $removed = $this->manager->moveUser($target, $domain, $actor);
            $this->addFlash('success', \sprintf('%s now belongs to %s.%s', $target->getUsername(), $domain->getPath(), $removed > 0 ? \sprintf(' %d grant(s) outside it removed.', $removed) : ''));
        }

        return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
    }

    #[Route('/users', name: 'app_admin_users', methods: ['GET'])]
    public function users(Request $request): Response
    {
        $actor = $this->actor();
        $query = trim($request->query->getString('q'));
        $scope = $this->policy->isPlatformStaff($actor) ? null : array_values(array_filter(
            $this->policy->visibleDomains($actor),
            fn (Domain $d): bool => $this->policy->levelOn($actor, '@access', $d)->includes(AccessLevel::Read),
        ));

        return $this->page('admin/users.html.twig', [
            'query' => $query,
            'users' => $this->users->search($query, $scope),
        ]);
    }

    #[Route('/users/{id}', name: 'app_admin_user', requirements: ['id' => '\d+'], methods: ['GET'])]
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

    #[Route('/users/{id}/grants', name: 'app_admin_user_grant', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function grant(Request $request, int $id): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_user');
        $target = $this->target($id);
        $domain = $this->findDomain($request->request->getString('domain'));
        $includeSubdomains = $request->request->getBoolean('subdomains');

        try {
            $expression = AccessExpression::parse($request->request->getString('rights'));
            $denial = $expression->isEmpty() ? 'Give at least one rule, or remove the grant.' : null;
        } catch (InvalidAccessExpression $e) {
            $denial = $e->getMessage();
        }
        $denial ??= $this->policy->grantDenial($actor, $target, $domain, $expression, $includeSubdomains, $this->manager->findGrant($target, $domain));

        if (null !== $denial) {
            $this->addFlash('error', $denial);
        } else {
            $this->manager->grant($target, $domain, $expression, $includeSubdomains, $actor);
            $this->addFlash('success', \sprintf('Rights on %s saved. They apply from the next token of %s.', $domain->getPath(), $target->getUsername()));
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id, 'preview' => $domain->getCode()]);
    }

    #[Route('/users/{id}/grants/{grant}/delete', name: 'app_admin_user_revoke', requirements: ['id' => '\d+', 'grant' => '\d+'], methods: ['POST'])]
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

    #[Route('/users/{id}/roles', name: 'app_admin_user_roles', requirements: ['id' => '\d+'], methods: ['POST'])]
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

    #[Route('/resource-types', name: 'app_admin_resource_types', methods: ['GET', 'POST'])]
    public function resourceTypes(Request $request): Response
    {
        $actor = $this->actor();
        $canEdit = $this->policy->isSuperAdmin($actor);

        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                throw $this->createAccessDeniedException();
            }
            $this->checkCsrf($request, 'admin_resource_type');
            $code = strtolower(trim($request->request->getString('code')));
            $type = $this->em->find(ResourceType::class, $code);
            try {
                if ($request->request->getBoolean('remove')) {
                    null !== $type && $this->em->remove($type);
                } else {
                    $label = trim($request->request->getString('label')) ?: $code;
                    null === $type ? $this->em->persist(new ResourceType($code, $label)) : $type->setLabel($label);
                }
                $this->em->flush();
                $this->addFlash('success', 'Resource types saved.');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_admin_resource_types');
        }

        return $this->page('admin/resource_types.html.twig', [
            'types' => $this->em->getRepository(ResourceType::class)->findBy([], ['code' => 'ASC']),
            'reserved' => ResourceType::RESERVED,
            'can_edit' => $canEdit,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters): Response
    {
        return SecurityController::harden($this->render($template, $parameters + ['actor' => $this->actor()]));
    }

    private function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->policy->canAdminister($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findDomain(string $code): Domain
    {
        return $this->domains->findByCode($code) ?? throw $this->createNotFoundException();
    }

    private function target(int $id): User
    {
        return $this->users->find($id) ?? throw $this->createNotFoundException();
    }

    private function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function canChangeOwner(User $actor, Domain $domain): bool
    {
        if ($this->policy->isSuperAdmin($actor)) {
            return true;
        }
        $parent = $domain->getParent();

        return null !== $parent && $this->policy->levelOn($actor, '@domain', $parent)->includes(AccessLevel::Write);
    }

    /**
     * @return list<Domain> from the root down to the parent of $domain
     */
    private function ancestors(Domain $domain): array
    {
        $ancestors = [];
        for ($d = $domain->getParent(); null !== $d; $d = $d->getParent()) {
            array_unshift($ancestors, $d);
        }

        return $ancestors;
    }
}
