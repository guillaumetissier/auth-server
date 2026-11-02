<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E-mail address of the users, and the single-use password link (reset or invitation).
 */
final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User e-mail and password links';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        $this->addSql('ALTER TABLE users ADD email VARCHAR(180) DEFAULT NULL, ADD password_link_hash VARCHAR(64) DEFAULT NULL, ADD password_link_expires_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9FB0BA533 ON users (password_link_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_1483A5E9FB0BA533 ON users');
        $this->addSql('DROP INDEX UNIQ_1483A5E9E7927C74 ON users');
        $this->addSql('ALTER TABLE users DROP email, DROP password_link_hash, DROP password_link_expires_at');
    }
}
