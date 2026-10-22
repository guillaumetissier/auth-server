<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema: credentials (users), OAuth 2 clients and tokens, DPoP bindings and replay cache.
 */
final class Version20261021000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        $this->addSql('CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, external_user_id VARCHAR(64) NOT NULL, totp_secret LONGTEXT DEFAULT NULL, totp_enabled_at DATETIME DEFAULT NULL, backup_codes JSON NOT NULL, failed_login_attempts INT UNSIGNED DEFAULT 0 NOT NULL, locked_until DATETIME DEFAULT NULL, enabled TINYINT NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1483A5E9F85E0677 (username), UNIQUE INDEX UNIQ_1483A5E97ECC0AD6 (external_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_client_policy (require_dpop TINYINT NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (client)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dpop_replay (proof_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_dpop_replay_expires_at (expires_at), PRIMARY KEY (proof_hash)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_dpop_binding (jkt VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, refresh_token CHAR(80) NOT NULL, PRIMARY KEY (refresh_token)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_authorization_code (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked TINYINT NOT NULL, client VARCHAR(32) NOT NULL, INDEX IDX_509FEF5FC7440455 (client), PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_refresh_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, revoked TINYINT NOT NULL, access_token CHAR(80) DEFAULT NULL, INDEX IDX_4DD90732B6A2DD68 (access_token), PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_client (name VARCHAR(128) NOT NULL, secret VARCHAR(128) DEFAULT NULL, redirect_uris TEXT DEFAULT NULL, grants TEXT DEFAULT NULL, scopes TEXT DEFAULT NULL, active TINYINT NOT NULL, allow_plain_text_pkce TINYINT DEFAULT 0 NOT NULL, identifier VARCHAR(32) NOT NULL, PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth2_access_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked TINYINT NOT NULL, client VARCHAR(32) NOT NULL, INDEX IDX_454D9673C7440455 (client), PRIMARY KEY (identifier)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE oauth2_client_policy ADD CONSTRAINT FK_2F4D6802C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_dpop_binding ADD CONSTRAINT FK_CF3F1DE9C74F2195 FOREIGN KEY (refresh_token) REFERENCES oauth2_refresh_token (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_authorization_code ADD CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE oauth2_access_token ADD CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_client_policy DROP FOREIGN KEY FK_2F4D6802C7440455');
        $this->addSql('ALTER TABLE oauth2_dpop_binding DROP FOREIGN KEY FK_CF3F1DE9C74F2195');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP FOREIGN KEY FK_509FEF5FC7440455');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP FOREIGN KEY FK_4DD90732B6A2DD68');
        $this->addSql('ALTER TABLE oauth2_access_token DROP FOREIGN KEY FK_454D9673C7440455');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE oauth2_client_policy');
        $this->addSql('DROP TABLE dpop_replay');
        $this->addSql('DROP TABLE oauth2_dpop_binding');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_refresh_token');
        $this->addSql('DROP TABLE oauth2_client');
        $this->addSql('DROP TABLE oauth2_access_token');
    }
}
