<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Domain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domain>
 */
final class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    public function findByCode(string $code): ?Domain
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * $root and all its descendants (or the whole tree), in tree order.
     *
     * @return list<Domain>
     */
    public function subtree(?Domain $root = null): array
    {
        $qb = $this->createQueryBuilder('d')->orderBy('d.path', 'ASC');
        if (null !== $root) {
            $qb->where('d.path LIKE :prefix')->setParameter('prefix', addcslashes($root->getPath(), '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Domains owned by $user.
     *
     * @return list<Domain>
     */
    public function ownedBy(object $user): array
    {
        return $this->findBy(['owner' => $user], ['path' => 'ASC']);
    }
}
