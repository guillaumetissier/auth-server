<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Access profiles: named sets of rights, given to users through their grants.
 */
final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Access profiles';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        $this->addSql('CREATE TABLE access_profile (
            code VARCHAR(63) NOT NULL,
            name VARCHAR(255) NOT NULL,
            expression VARCHAR(1000) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(code)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE access_grant ADD profile_code VARCHAR(63) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_20901A1FC1DDBF0C ON access_grant (profile_code)');
        $this->addSql('ALTER TABLE access_grant ADD CONSTRAINT FK_20901A1FC1DDBF0C FOREIGN KEY (profile_code) REFERENCES access_profile (code) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_grant DROP FOREIGN KEY FK_20901A1FC1DDBF0C');
        $this->addSql('DROP INDEX IDX_20901A1FC1DDBF0C ON access_grant');
        $this->addSql('ALTER TABLE access_grant DROP profile_code');
        $this->addSql('DROP TABLE access_profile');
    }
}
