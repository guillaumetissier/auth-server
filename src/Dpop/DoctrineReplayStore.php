<?php

declare(strict_types=1);

namespace App\Dpop;

use GuillaumeTissier\ResourceGuard\Dpop\ReplayStoreInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/**
 * MySQL-backed DPoP replay store: the primary key makes "insert if absent" atomic, so two
 * concurrent requests replaying the same proof cannot both succeed.
 *
 * Implements the same interface as a future Redis store (SET key NX EX ttl): switching is a
 * one-line change in config/services.yaml.
 */
final class DoctrineReplayStore implements ReplayStoreInterface
{
    /** Roughly one call in PURGE_EVERY removes expired rows. */
    private const PURGE_EVERY = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function remember(string $key, \DateTimeImmutable $expiresAt): bool
    {
        if (1 === random_int(1, self::PURGE_EVERY)) {
            $this->purgeExpired();
        }

        try {
            $this->connection->insert('dpop_replay', [
                'proof_hash' => hash('sha256', $key),
                'expires_at' => $expiresAt,
            ], ['expires_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function purgeExpired(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM dpop_replay WHERE expires_at < :now',
            ['now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
