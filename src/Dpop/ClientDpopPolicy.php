<?php

declare(strict_types=1);

namespace App\Dpop;

use App\Entity\ClientPolicy;
use App\Settings\SystemSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells whether a client must use DPoP-bound tokens: its own policy, otherwise the system setting.
 */
final readonly class ClientDpopPolicy
{
    public function __construct(
        private EntityManagerInterface $em,
        private SystemSettings $settings,
    ) {
    }

    public function requiresDpop(?string $clientId): bool
    {
        if (null === $clientId || '' === $clientId) {
            return $this->settings->dpopRequiredByDefault();
        }

        $policy = $this->em->getRepository(ClientPolicy::class)->findOneBy(['client' => $clientId]);

        return $policy?->requiresDpop() ?? $this->settings->dpopRequiredByDefault();
    }
}
