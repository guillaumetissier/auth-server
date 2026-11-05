<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\ResourceCatalog;
use App\Entity\ResourceType;
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
                    $message = \sprintf('"%s" removed.', $code);
                } else {
                    // Creating never overwrites an existing entry, editing never creates one.
                    $exists = isset($this->catalog->knownCodes()[$code]);
                    if ('edit' !== $mode && $exists) {
                        throw new \InvalidArgumentException(\sprintf('"%s" already exists: use Edit to change it.', $code));
                    }
                    if ('edit' === $mode && !$exists) {
                        throw new \InvalidArgumentException(\sprintf('Unknown resource type or facet "%s".', $code));
                    }
                    $this->catalog->declare($code, $request->request->getString('label'), self::optional($request, 'description'), $actor);
                    $message = \sprintf('"%s" %s.', $code, 'edit' === $mode ? 'saved' : 'created');
                }
                $this->em->flush();
                $this->addFlash('success', $message);
            } catch (\InvalidArgumentException|\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
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
