<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\ProfileManager;
use App\Access\ResourceCatalog;
use App\Entity\AccessGrant;
use App\Entity\AccessProfile;
use App\Entity\ResourceType;
use App\Entity\User;
use App\I18n\Text;
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
 * Creating and editing happen in a dialog (templates/admin/_profile_dialog.html.twig). Changing the rights
 * of a profile given to users first shows whose rights change, and how; saving needs a confirmation.
 *
 * @phpstan-type Values array{code: string, name: string, rights: string, description: string}
 * @phpstan-type Dialog array{mode: 'create'|'edit', values: Values, impact: list<array{grant: AccessGrant, before: AccessExpression, after: AccessExpression}>, error: \Symfony\Contracts\Translation\TranslatableInterface|string|null}
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
        return $this->listPage(null);
    }

    #[Route('', name: 'app_admin_profile_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->editor($request);
        $values = $this->posted($request);
        try {
            $profile = $this->profiles->save($values['code'], $values['name'], AccessExpression::parse($values['rights']), $values['description'], $actor, true);
            $this->em->flush();
            $this->addFlash('success', new Text('admin.profile.profile_name_created_give_it', ['{name}' => $profile->getName()]));

            return $this->redirectToRoute('app_admin_profiles');
        } catch (\InvalidArgumentException|InvalidAccessExpression $e) {
            return $this->listPage(['mode' => 'create', 'values' => $values, 'impact' => [], 'error' => Text::of($e)]);
        }
    }

    #[Route('/{code}', name: 'app_admin_profile', methods: ['GET'])]
    public function show(string $code): Response
    {
        $profile = $this->profiles->find($code) ?? throw $this->createNotFoundException();

        return $this->detailPage($profile, null);
    }

    /**
     * Saves the dialog of the list or the detail page ("from"). When the rights of users change, the same page
     * comes back with the dialog open on whose rights change, and saving needs a confirmation of these rights.
     */
    #[Route('/{code}', name: 'app_admin_profile_update', methods: ['POST'])]
    public function update(Request $request, string $code): Response
    {
        $actor = $this->editor($request);
        $profile = $this->profiles->find($code) ?? throw $this->createNotFoundException();
        $fromDetail = 'detail' === $request->request->getString('from');
        $values = ['code' => $profile->getCode()] + $this->posted($request);

        try {
            $expression = AccessExpression::parse($values['rights']);
            $impact = $this->profiles->impact($profile, $expression);
            if ([] === $impact || $request->request->getString('confirmed') === $expression->toString()) {
                $this->profiles->save($profile->getCode(), $values['name'], $expression, $values['description'], $actor, false);
                $this->em->flush();
                $this->addFlash('success', [] === $impact
                    ? new Text('admin.profile.profile_name_saved', ['{name}' => $values['name']])
                    : new Text('admin.profile.profile_name_saved_rights_count', ['{name}' => $values['name'], '{count}' => \count($impact)]));

                return $fromDetail
                    ? $this->redirectToRoute('app_admin_profile', ['code' => $profile->getCode()])
                    : $this->redirectToRoute('app_admin_profiles');
            }
            $values['rights'] = $expression->toString();
            $dialog = ['mode' => 'edit', 'values' => $values, 'impact' => $impact, 'error' => null];
        } catch (\InvalidArgumentException|InvalidAccessExpression $e) {
            $dialog = ['mode' => 'edit', 'values' => $values, 'impact' => [], 'error' => Text::of($e)];
        }

        return $fromDetail ? $this->detailPage($profile, $dialog) : $this->listPage($dialog);
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
            $this->addFlash('success', new Text('admin.profile.profile_name_removed', ['{name}' => $profile->getName()]));
        } catch (\DomainException $e) {
            $this->addFlash('error', Text::of($e));
        }

        return $this->redirectToRoute('app_admin_profiles');
    }

    /**
     * @return Values
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
     * The super-administrator, after the CSRF check of the profile forms.
     */
    private function editor(Request $request): User
    {
        $actor = $this->actor();
        if (!$this->policy->isSuperAdmin($actor)) {
            throw $this->createAccessDeniedException();
        }
        $this->checkCsrf($request, 'admin_profile');

        return $actor;
    }

    /**
     * @param Dialog|null $dialog the profile dialog to open at once (an error, or rights to confirm)
     */
    private function listPage(?array $dialog): Response
    {
        $actor = $this->actor();

        return $this->page('admin/profiles.html.twig', [
            'profiles' => $this->profiles->profiles(),
            'usage' => $this->profiles->usageCounts(),
            'can_edit' => $this->policy->isSuperAdmin($actor),
            'dialog' => $dialog,
        ] + $this->typeHelp());
    }

    /**
     * @param Dialog|null $dialog
     */
    private function detailPage(AccessProfile $profile, ?array $dialog): Response
    {
        $actor = $this->actor();

        return $this->page('admin/profile.html.twig', [
            'profile' => $profile,
            'grants' => $this->profiles->grantsOf($profile),
            'can_edit' => $this->policy->isSuperAdmin($actor),
            'dialog' => $dialog,
        ] + $this->typeHelp());
    }

    /**
     * @return array{resource_types: list<ResourceType>, reserved_types: array<string, string>}
     */
    private function typeHelp(): array
    {
        return ['resource_types' => $this->catalog->types(), 'reserved_types' => ResourceType::RESERVED];
    }
}
