<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DPoP proof identifiers already seen by the token endpoint (replay protection).
 * Written through DBAL by DoctrineReplayStore; mapped here so the schema is managed by migrations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dpop_replay')]
#[ORM\Index(name: 'idx_dpop_replay_expires_at', columns: ['expires_at'])]
class DpopReplay
{
    /** SHA-256 of "jkt|jti". */
    #[ORM\Id]
    #[ORM\Column(name: 'proof_hash', length: 64, options: ['fixed' => true])]
    private string $proofHash;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    private function __construct()
    {
    }
}
