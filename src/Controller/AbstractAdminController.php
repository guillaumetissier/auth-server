<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessPolicy;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Shared by the administration pages: who is acting, lookups, CSRF, page rendering.
 */
abstract class AbstractAdminController extends AbstractController
{
    protected AccessPolicy $policy;
    protected DomainRepository $domains;
    protected UserRepository $users;

    #[Required]
    public function setAdminDependencies(AccessPolicy $policy, DomainRepository $domains, UserRepository $users): void
    {
        $this->policy = $policy;
        $this->domains = $domains;
        $this->users = $users;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function page(string $template, array $parameters): Response
    {
        return SecurityController::harden($this->render($template, $parameters + ['actor' => $this->actor()]));
    }

    /**
     * The signed-in user, provided he may open the administration pages at all.
     */
    protected function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->policy->canAdminister($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    protected function findDomain(string $code): Domain
    {
        return $this->domains->findByCode($code) ?? throw $this->createNotFoundException();
    }

    protected function target(int $id): User
    {
        return $this->users->find($id) ?? throw $this->createNotFoundException();
    }

    protected function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Value of an optional text field: trimmed, null when empty.
     */
    protected static function optional(Request $request, string $field): ?string
    {
        $value = trim($request->request->getString($field));

        return '' === $value ? null : $value;
    }
}
