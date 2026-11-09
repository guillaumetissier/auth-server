<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Language of each user (interface and e-mails).
 */
final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User language';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');
        $this->addSql('ALTER TABLE users ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP locale');
    }
}
