<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grace period of the 2FA roll-out: how often and when each user postponed the enrollment.
 */
final class Version20261021170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '2FA postponement tracking';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        $this->addSql('ALTER TABLE users ADD two_factor_postpone_count INT UNSIGNED DEFAULT 0 NOT NULL AFTER backup_codes, ADD two_factor_postponed_at DATETIME DEFAULT NULL AFTER two_factor_postpone_count');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP two_factor_postpone_count, DROP two_factor_postponed_at');
    }
}
