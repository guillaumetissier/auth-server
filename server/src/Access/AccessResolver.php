<?php

declare(strict_types=1);

namespace App\Access;

use App\Entity\AccessGrant;
use App\Entity\User;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuillaumeTissier\ResourceGuard\Access\AccessControlList;
use GuillaumeTissier\ResourceGuard\Access\AccessEntry;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Computes the effective rights of a user, as carried in access tokens ("dom" and "acl" claims):
 *  - his grants;
 *  - the owner expression of every domain he owns, on that domain and its subdomains;
 *  - ACCESS_DEFAULT_EXPRESSION on his own domain (e.g. "user:w:own": everyone edits his own profile).
 */
final readonly class AccessResolver
{
    private AccessExpression $defaultExpression;

    public function __construct(
        private EntityManagerInterface $em,
        private DomainRepository $domains,
        #[Autowire('%env(ACCESS_DEFAULT_EXPRESSION)%')] string $defaultExpression,
    ) {
        $this->defaultExpression = AccessExpression::parse($defaultExpression);
    }

    public function aclFor(User $user): AccessControlList
    {
        $entries = [];
        foreach ($this->grantsOf($user) as $grant) {
            $entries[] = new AccessEntry($grant->getDomain()->getPath(), $grant->includesSubdomains(), $grant->getExpression());
        }
        foreach ($this->domains->ownedBy($user) as $domain) {
            $entries[] = new AccessEntry($domain->getPath(), true, $domain->getOwnerExpression());
        }
        $domain = $user->getDomain();
        if (null !== $domain && !$this->defaultExpression->isEmpty()) {
            $entries[] = new AccessEntry($domain->getPath(), true, $this->defaultExpression);
        }

        return new AccessControlList($domain?->getPath(), $entries);
    }

    /**
     * @return list<AccessGrant>
     */
    public function grantsOf(User $user): array
    {
        return $this->em->getRepository(AccessGrant::class)->createQueryBuilder('g')
            ->join('g.domain', 'd')->addSelect('d')
            ->where('g.user = :user')->setParameter('user', $user)
            ->orderBy('d.path', 'ASC')
            ->getQuery()->getResult();
    }

    public function defaultExpression(): AccessExpression
    {
        return $this->defaultExpression;
    }
}
