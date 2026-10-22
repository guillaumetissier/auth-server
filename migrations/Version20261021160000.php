<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Administrator roles and impersonation sessions.
 */
final class Version20261021160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User roles, impersonation sessions and their code/token links';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        // JSON columns cannot take a literal default: add nullable, fill, then enforce NOT NULL.
        $this->addSql('ALTER TABLE users ADD roles JSON DEFAULT NULL AFTER external_user_id');
        $this->addSql('UPDATE users SET roles = JSON_ARRAY()');
        $this->addSql('ALTER TABLE users MODIFY roles JSON NOT NULL');

        $this->addSql('CREATE TABLE impersonation_session (id VARCHAR(36) NOT NULL, mode VARCHAR(16) NOT NULL, reason VARCHAR(500) NOT NULL, started_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, end_reason VARCHAR(32) DEFAULT NULL, admin_id INT UNSIGNED NOT NULL, target_id INT UNSIGNED NOT NULL, INDEX idx_impersonation_expires_at (expires_at), INDEX IDX_C7AA2315642B8210 (admin_id), INDEX IDX_C7AA2315158E0B66 (target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE impersonation_link (token_id VARCHAR(80) NOT NULL, token_type VARCHAR(16) NOT NULL, session_id VARCHAR(36) NOT NULL, INDEX IDX_5D6980AE613FECDF (session_id), PRIMARY KEY (token_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT FK_C7AA2315642B8210 FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT FK_C7AA2315158E0B66 FOREIGN KEY (target_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE impersonation_link ADD CONSTRAINT FK_5D6980AE613FECDF FOREIGN KEY (session_id) REFERENCES impersonation_session (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE impersonation_link DROP FOREIGN KEY FK_5D6980AE613FECDF');
        $this->addSql('ALTER TABLE impersonation_session DROP FOREIGN KEY FK_C7AA2315642B8210');
        $this->addSql('ALTER TABLE impersonation_session DROP FOREIGN KEY FK_C7AA2315158E0B66');
        $this->addSql('DROP TABLE impersonation_link');
        $this->addSql('DROP TABLE impersonation_session');
        $this->addSql('ALTER TABLE users DROP roles');
    }
}
