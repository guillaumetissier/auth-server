<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resource types: the kinds of objects rights apply to. Changed by the super-administrator only.
 *
 * Users: AdminUserController. Domains: AdminDomainController.
 */
#[Route('/admin')]
final class AdminController extends AbstractAdminController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
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
}
