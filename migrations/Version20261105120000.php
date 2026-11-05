<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Facets of resource types ("vehicle.pricing"): parts of a resource granted on their own.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resource facets';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration targets MySQL.');

        // A dot now separates a type from its facet: a type declared with a dot no longer matches anything.
        $dotted = array_filter($this->connection->fetchFirstColumn("SELECT code FROM resource_type WHERE code LIKE '%.%'"), \is_string(...));
        $this->warnIf([] !== $dotted, \sprintf(
            'Resource type(s) with a dot: %s. In access expressions "a.b" now means the facet "b" of "a": declare "a" and its facet "b", move the rights, then remove the old type.',
            implode(', ', $dotted),
        ));

        $this->addSql('CREATE TABLE resource_facet (
            code VARCHAR(127) NOT NULL,
            type_code VARCHAR(63) NOT NULL,
            name VARCHAR(63) NOT NULL,
            label VARCHAR(255) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            INDEX IDX_7D02C01AA01AF590 (type_code),
            PRIMARY KEY(code)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE resource_facet ADD CONSTRAINT FK_7D02C01AA01AF590 FOREIGN KEY (type_code) REFERENCES resource_type (code) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE resource_facet');
    }
}
