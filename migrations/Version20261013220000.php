<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Test data management: test_runs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE test_runs (id VARCHAR(32) NOT NULL, fixture VARCHAR(32) NOT NULL, fixture_version INT NOT NULL, seed INT NOT NULL, manifest LONGTEXT NOT NULL, manifest_hash VARCHAR(64) NOT NULL, token_hash VARCHAR(64) NOT NULL, clock_offset_seconds INT DEFAULT 0 NOT NULL, resets INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // Forgets the runs; their data stays until purged with "app:fixtures:sharing:purge <run-id>".
        $this->addSql('DROP TABLE test_runs');
    }
}
