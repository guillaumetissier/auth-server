<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * System settings changed in the administration pages (they take precedence over the environment).
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'System settings';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        $this->addSql('CREATE TABLE system_setting (
            name VARCHAR(64) NOT NULL,
            value VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            updated_by_id INT UNSIGNED DEFAULT NULL,
            INDEX IDX_7307C40B896DBBDE (updated_by_id),
            PRIMARY KEY(name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE system_setting ADD CONSTRAINT FK_7307C40B896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE system_setting');
    }
}
