<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\ProfileManager;
use App\Access\ResourceCatalog;
use App\Entity\AccessProfile;
use App\Entity\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use GuillaumeTissier\ResourceGuard\Access\InvalidAccessExpression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Access profiles: named sets of rights given to users on a domain (user page). Created, changed and removed
 * by the super-administrator; everyone who administers users sees them.
 *
 * Changing the rights of a profile given to users first shows whose rights change, and how; saving
 * needs a confirmation.
 */
#[Route('/admin/profiles')]
final class AdminProfileController extends AbstractAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileManager $profiles,
        private readonly ResourceCatalog $catalog,
    ) {
    }

    #[Route('', name: 'app_admin_profiles', methods: ['GET'])]
    public function list(): Response
    {
        $actor = $this->actor();

        return $this->page('admin/profiles.html.twig', [
            'profiles' => $this->profiles->profiles(),
            'usage' => $this->profiles->usageCounts(),
            'can_edit' => $this->policy->isSuperAdmin($actor),
        ]);
    }

    #[Route('/new', name: 'app_admin_profile_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->actor();
        if (!$this->policy->isSuperAdmin($actor)) {
            throw $this->createAccessDeniedException();
        }

        $values = ['code' => '', 'name' => '', 'rights' => '', 'description' => ''];
        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'admin_profile');
            $values = $this->posted($request);
            try {
                $profile = $this->profiles->save($values['code'], $values['name'], AccessExpression::parse($values['rights']), $values['description'], $actor, true);
                $this->em->flush();
                $this->addFlash('success', \sprintf('Profile "%s" created. Give it to users from their page.', $profile->getName()));

                return $this->redirectToRoute('app_admin_profile', ['code' => $profile->getCode()]);
            } catch (\InvalidArgumentException|InvalidAccessExpression $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->form(null, $values, true, []);
    }

    #[Route('/{code}', name: 'app_admin_profile', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $code): Response
    {
        $actor = $this->actor();
        $profile = $this->profiles->find($code) ?? throw $this->createNotFoundException();
        $canEdit = $this->policy->isSuperAdmin($actor);

        $values = [
            'code' => $profile->getCode(),
            'name' => $profile->getName(),
            'rights' => $profile->getExpression()->toString(),
            'description' => $profile->getDescription() ?? '',
        ];
        $impact = [];
        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                throw $this->createAccessDeniedException();
            }
            $this->checkCsrf($request, 'admin_profile');
            $values = ['code' => $profile->getCode()] + $this->posted($request);
            try {
                $expression = AccessExpression::parse($values['rights']);
                $impact = $this->profiles->impact($profile, $expression);
                // Rights of users change: show how, and save only once confirmed with the same rights.
                if ([] === $impact || $request->request->getString('confirmed') === $expression->toString()) {
                    $this->profiles->save($profile->getCode(), $values['name'], $expression, $values['description'], $actor, false);
                    $this->em->flush();
                    $this->addFlash('success', [] === $impact
                        ? \sprintf('Profile "%s" saved.', $values['name'])
                        : \sprintf('Profile "%s" saved: the rights of %d grant(s) changed, from the next token of each user.', $values['name'], \count($impact)));

                    return $this->redirectToRoute('app_admin_profile', ['code' => $profile->getCode()]);
                }
                $values['rights'] = $expression->toString();
            } catch (\InvalidArgumentException|InvalidAccessExpression $e) {
                $this->addFlash('error', $e->getMessage());
                $impact = [];
            }
        }

        return $this->form($profile, $values, $canEdit, $impact);
    }

    #[Route('/{code}/delete', name: 'app_admin_profile_delete', methods: ['POST'])]
    public function delete(Request $request, string $code): Response
    {
        $actor = $this->actor();
        if (!$this->policy->isSuperAdmin($actor)) {
            throw $this->createAccessDeniedException();
        }
        $this->checkCsrf($request, 'admin_profile');
        $profile = $this->profiles->find($code) ?? throw $this->createNotFoundException();
        try {
            $this->profiles->remove($profile, $actor);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Profile "%s" removed.', $profile->getName()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_profiles');
    }

    /**
     * @return array{code: string, name: string, rights: string, description: string}
     */
    private function posted(Request $request): array
    {
        return [
            'code' => strtolower(trim($request->request->getString('code'))),
            'name' => trim($request->request->getString('name')),
            'rights' => trim($request->request->getString('rights')),
            'description' => trim($request->request->getString('description')),
        ];
    }

    /**
     * @param array{code: string, name: string, rights: string, description: string}                         $values
     * @param list<array{grant: \App\Entity\AccessGrant, before: AccessExpression, after: AccessExpression}> $impact
     */
    private function form(?AccessProfile $profile, array $values, bool $canEdit, array $impact): Response
    {
        return $this->page('admin/profile.html.twig', [
            'profile' => $profile,
            'values' => $values,
            'can_edit' => $canEdit,
            'impact' => $impact,
            'grants' => null === $profile ? [] : $this->profiles->grantsOf($profile),
            'resource_types' => $this->catalog->types(),
            'reserved_types' => ResourceType::RESERVED,
        ]);
    }
}
