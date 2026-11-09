<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\ResourceCatalog;
use App\Entity\ResourceType;
use App\I18n\InvalidInput;
use App\I18n\Text;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resource types and their facets: the kinds of objects, and parts of objects, rights apply to.
 * Changed by the super-administrator only; platform staff see them.
 *
 * Users: AdminUserController. Domains: AdminDomainController.
 */
#[Route('/admin')]
final class AdminController extends AbstractAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResourceCatalog $catalog,
    ) {
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

            // A new facet is posted as its type and its name; anything else as its code.
            $type = strtolower(trim($request->request->getString('type')));
            $name = strtolower(trim($request->request->getString('facet')));
            $code = '' !== $type && '' !== $name ? $type.'.'.$name : strtolower(trim($request->request->getString('code')));
            $mode = $request->request->getString('mode', 'create');
            try {
                if ('remove' === $mode) {
                    $this->catalog->remove($code, $actor);
                    $message = new Text('admin.resource_types.code_removed', ['{code}' => $code]);
                } else {
                    // Creating never overwrites an existing entry, editing never creates one.
                    $exists = isset($this->catalog->knownCodes()[$code]);
                    if ('edit' !== $mode && $exists) {
                        throw new InvalidInput('admin.resource_types.code_already_exists_use_edit', ['{code}' => $code]);
                    }
                    if ('edit' === $mode && !$exists) {
                        throw new InvalidInput('common.unknown_resource_type_or_facet', ['{code}' => $code]);
                    }
                    $this->catalog->declare($code, $request->request->getString('label'), self::optional($request, 'description'), $actor);
                    $message = 'edit' === $mode ? new Text('admin.resource_types.code_saved', ['{code}' => $code]) : new Text('admin.resource_types.code_created', ['{code}' => $code]);
                }
                $this->em->flush();
                $this->addFlash('success', $message);
            } catch (\InvalidArgumentException|\DomainException $e) {
                $this->addFlash('error', Text::of($e));
            }

            return $this->redirectToRoute('app_admin_resource_types');
        }

        return $this->page('admin/resource_types.html.twig', [
            'types' => $this->catalog->types(),
            'usage' => $this->catalog->usageCounts(),
            'reserved' => ResourceType::RESERVED,
            'can_edit' => $canEdit,
        ]);
    }
}
