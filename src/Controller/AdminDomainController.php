<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessManager;
use App\Entity\AccessGrant;
use App\Entity\Domain;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Domains: tree, creation, renaming, deletion, owner, members. Decided by AccessPolicy: the
 * super-administrator does everything; a holder of @domain:w manages the subdomains of that domain.
 */
#[Route('/admin')]
final class AdminDomainController extends AbstractAdminController
{
    public function __construct(
        private readonly AccessManager $manager,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_domains', methods: ['GET'])]
    public function domains(): Response
    {
        $actor = $this->actor();
        $domains = $this->policy->visibleDomains($actor);

        return $this->page('admin/domains.html.twig', [
            'domains' => $domains,
            'members' => $this->users->memberCounts($domains),
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
                $this->audit('domain.created', $domain, $actor);
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
        $members = $this->users->membersOf($domain);

        return $this->page('admin/domain.html.twig', [
            'domain' => $domain,
            'ancestors' => $this->ancestors($domain),
            'children' => $children,
            'members' => $canSeeUsers ? $members : [],
            'can_see_users' => $canSeeUsers,
            'can_add_members' => $this->policy->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write),
            'can_create_users' => $this->policy->levelOn($actor, '@access', $domain)->includes(AccessLevel::Write) || $this->policy->isSuperAdmin($actor),
            'can_create_subdomain' => $this->policy->canCreateSubdomain($actor, $domain),
            'can_change_owner' => $this->canChangeOwner($actor, $domain),
            'rename_denial' => $this->policy->domainRenameDenial($actor, $domain),
            'delete_denial' => $this->policy->domainDeleteDenial($actor, $domain, \count($members), $this->domains->countChildren($domain)),
        ]);
    }

    #[Route('/domains/{code}/rename', name: 'app_admin_domain_rename', methods: ['POST'])]
    public function rename(Request $request, string $code): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_domain');
        $domain = $this->findDomain($code);
        $name = trim($request->request->getString('name'));

        $denial = $this->policy->domainRenameDenial($actor, $domain) ?? ('' === $name || mb_strlen($name) > 255 ? 'Give a name of 1 to 255 characters.' : null);
        if (null !== $denial) {
            $this->addFlash('error', $denial);
        } else {
            $domain->setName($name);
            $this->em->flush();
            $this->audit('domain.renamed', $domain, $actor);
            $this->addFlash('success', 'Domain renamed.');
        }

        return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
    }

    /**
     * Only empty domains (no user, no subdomain): the grants on it go with it.
     */
    #[Route('/domains/{code}/delete', name: 'app_admin_domain_delete', methods: ['POST'])]
    public function delete(Request $request, string $code): Response
    {
        $actor = $this->actor();
        $this->checkCsrf($request, 'admin_domain');
        $domain = $this->findDomain($code);

        $denial = $this->policy->domainDeleteDenial($actor, $domain, \count($this->users->membersOf($domain, 1)), $this->domains->countChildren($domain));
        if (null === $denial && $request->request->getString('confirm') !== $domain->getCode()) {
            $denial = \sprintf('Type the code of the domain (%s) to confirm.', $domain->getCode());
        }
        if (null !== $denial) {
            $this->addFlash('error', $denial);

            return $this->redirectToRoute('app_admin_domain', ['code' => $code]);
        }

        $parent = $domain->getParent();
        $this->audit('domain.deleted', $domain, $actor);
        $this->em->createQuery('DELETE FROM '.AccessGrant::class.' g WHERE g.domain = :d')->setParameter('d', $domain)->execute();
        $this->em->remove($domain);
        $this->em->flush();
        $this->addFlash('success', \sprintf('Domain %s deleted.', $domain->getPath()));

        return null === $parent ? $this->redirectToRoute('app_admin_domains') : $this->redirectToRoute('app_admin_domain', ['code' => $parent->getCode()]);
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

    private function audit(string $event, Domain $domain, User $actor): void
    {
        $this->auditLogger->info($event, [
            'domain' => $domain->getPath(),
            'name' => $domain->getName(),
            'actor' => $actor->getUsername(),
            'actor_id' => $actor->getExternalUserId(),
        ]);
    }
}
