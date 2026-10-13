<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Controlled bug injection: which defects are switched on for a test run.';
    }

    public function up(Schema $schema): void
    {
        // NULL: none, which is what every existing run gets.
        $this->addSql('ALTER TABLE test_runs ADD active_bugs JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Every run behaves correctly again.
        $this->addSql('ALTER TABLE test_runs DROP active_bugs');
    }
}
