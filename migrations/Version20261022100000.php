<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Domains, resource types and access grants; impersonation entry point per client.
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domains and access rights';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        $this->addSql('CREATE TABLE domain (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            parent_id INT UNSIGNED DEFAULT NULL,
            owner_id INT UNSIGNED DEFAULT NULL,
            code VARCHAR(63) NOT NULL,
            name VARCHAR(255) NOT NULL,
            path VARCHAR(760) NOT NULL,
            owner_expression VARCHAR(1000) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_A7A91E0B77153098 (code),
            INDEX IDX_A7A91E0B727ACA70 (parent_id),
            INDEX IDX_A7A91E0B7E3C61F9 (owner_id),
            INDEX idx_domain_path (path),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE domain ADD CONSTRAINT FK_A7A91E0B727ACA70 FOREIGN KEY (parent_id) REFERENCES domain (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE domain ADD CONSTRAINT FK_A7A91E0B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE resource_type (
            code VARCHAR(63) NOT NULL,
            label VARCHAR(255) NOT NULL,
            PRIMARY KEY(code)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE access_grant (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            domain_id INT UNSIGNED NOT NULL,
            granted_by_id INT UNSIGNED DEFAULT NULL,
            include_subdomains TINYINT(1) NOT NULL,
            expression VARCHAR(1000) NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_ACCESS_GRANT_DOMAIN (domain_id),
            INDEX IDX_ACCESS_GRANT_GRANTED_BY (granted_by_id),
            UNIQUE INDEX uniq_access_grant_user_domain (user_id, domain_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE access_grant ADD CONSTRAINT FK_ACCESS_GRANT_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_grant ADD CONSTRAINT FK_ACCESS_GRANT_DOMAIN FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_grant ADD CONSTRAINT FK_ACCESS_GRANT_GRANTED_BY FOREIGN KEY (granted_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE users ADD domain_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_USERS_DOMAIN FOREIGN KEY (domain_id) REFERENCES domain (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_USERS_DOMAIN ON users (domain_id)');

        $this->addSql('ALTER TABLE oauth2_client_policy MODIFY require_dpop TINYINT(1) DEFAULT NULL, ADD impersonation_start_uri VARCHAR(2000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_client_policy DROP impersonation_start_uri');
        $this->addSql('UPDATE oauth2_client_policy SET require_dpop = 1 WHERE require_dpop IS NULL');
        $this->addSql('ALTER TABLE oauth2_client_policy MODIFY require_dpop TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_USERS_DOMAIN');
        $this->addSql('DROP INDEX IDX_USERS_DOMAIN ON users');
        $this->addSql('ALTER TABLE users DROP domain_id');
        $this->addSql('DROP TABLE access_grant');
        $this->addSql('DROP TABLE resource_type');
        $this->addSql('DROP TABLE domain');
    }
}
