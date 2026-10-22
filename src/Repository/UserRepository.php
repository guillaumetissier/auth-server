<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * Rehashes passwords transparently when the hashing algorithm or its cost changes.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * Users matching $query (username or external id), optionally limited to a domain subtree.
     *
     * @param list<\App\Entity\Domain>|null $domains null: everybody; otherwise only members of these domains
     *
     * @return list<User>
     */
    public function search(string $query, ?array $domains, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('u')->leftJoin('u.domain', 'd')->addSelect('d')
            ->orderBy('u.username', 'ASC')->setMaxResults($limit);
        if ('' !== $query) {
            $qb->andWhere('u.username LIKE :q OR u.externalUserId = :exact')
                ->setParameter('q', '%'.addcslashes($query, '%_').'%')->setParameter('exact', $query);
        }
        if (null !== $domains) {
            if ([] === $domains) {
                return [];
            }
            $qb->andWhere('u.domain IN (:domains)')->setParameter('domains', $domains);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<User>
     */
    public function membersOf(\App\Entity\Domain $domain, int $limit = 500): array
    {
        return $this->findBy(['domain' => $domain], ['username' => 'ASC'], $limit);
    }
}
