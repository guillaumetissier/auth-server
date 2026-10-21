<?php

declare(strict_types=1);

namespace App\Dpop;

use App\Entity\ClientPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells whether a client must use DPoP-bound tokens.
 */
final readonly class ClientDpopPolicy
{
    public function __construct(
        private EntityManagerInterface $em,
        private bool $dpopRequiredByDefault,
    ) {
    }

    public function requiresDpop(?string $clientId): bool
    {
        if (null === $clientId || '' === $clientId) {
            return $this->dpopRequiredByDefault;
        }

        $policy = $this->em->getRepository(ClientPolicy::class)->findOneBy(['client' => $clientId]);

        return $policy?->requiresDpop() ?? $this->dpopRequiredByDefault;
    }
}
