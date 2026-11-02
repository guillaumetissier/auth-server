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
     * Users matching $query (username, e-mail or external id), optionally limited to a domain subtree.
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
            $qb->andWhere('u.username LIKE :q OR u.email LIKE :q OR u.externalUserId = :exact')
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
     * Account figures for the dashboard.
     *
     * @param list<\App\Entity\Domain>|null $domains null: everybody; otherwise only members of these domains
     *
     * @return array{total: int, two_factor: int, disabled: int, locked: int, without_email: int}
     */
    public function stats(?array $domains, \DateTimeImmutable $now): array
    {
        $zero = ['total' => 0, 'two_factor' => 0, 'disabled' => 0, 'locked' => 0, 'without_email' => 0];
        if ([] === $domains) {
            return $zero;
        }
        $qb = $this->createQueryBuilder('u')->select(
            'COUNT(u.id) AS total',
            'SUM(CASE WHEN u.totpEnabledAt IS NOT NULL THEN 1 ELSE 0 END) AS two_factor',
            'SUM(CASE WHEN u.enabled = false THEN 1 ELSE 0 END) AS disabled',
            'SUM(CASE WHEN u.lockedUntil > :now THEN 1 ELSE 0 END) AS locked',
            'SUM(CASE WHEN u.email IS NULL THEN 1 ELSE 0 END) AS without_email',
        )->setParameter('now', $now);
        if (null !== $domains) {
            $qb->where('u.domain IN (:domains)')->setParameter('domains', $domains);
        }
        /** @var array<string, int|string|null> $row */
        $row = $qb->getQuery()->getSingleResult();

        foreach ($zero as $key => $value) {
            $zero[$key] = (int) ($row[$key] ?? 0);
        }

        return $zero;
    }

    /**
     * Users who signed in most recently.
     *
     * @param list<\App\Entity\Domain>|null $domains
     *
     * @return list<User>
     */
    public function recentLogins(?array $domains, int $limit = 8): array
    {
        if ([] === $domains) {
            return [];
        }
        $qb = $this->createQueryBuilder('u')->leftJoin('u.domain', 'd')->addSelect('d')
            ->where('u.lastLoginAt IS NOT NULL')->orderBy('u.lastLoginAt', 'DESC')->setMaxResults($limit);
        if (null !== $domains) {
            $qb->andWhere('u.domain IN (:domains)')->setParameter('domains', $domains);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Number of members of each domain.
     *
     * @param list<\App\Entity\Domain> $domains
     *
     * @return array<int, int> domain id => members
     */
    public function memberCounts(array $domains): array
    {
        if ([] === $domains) {
            return [];
        }
        $rows = $this->createQueryBuilder('u')->select('IDENTITY(u.domain) AS domain', 'COUNT(u.id) AS members')
            ->where('u.domain IN (:domains)')->setParameter('domains', $domains)
            ->groupBy('u.domain')->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            if (\is_array($row) && is_numeric($row['domain'] ?? null) && is_numeric($row['members'] ?? null)) {
                $counts[(int) $row['domain']] = (int) $row['members'];
            }
        }

        return $counts;
    }

    /**
     * @return list<User>
     */
    public function membersOf(\App\Entity\Domain $domain, int $limit = 500): array
    {
        return $this->findBy(['domain' => $domain], ['username' => 'ASC'], $limit);
    }
}
