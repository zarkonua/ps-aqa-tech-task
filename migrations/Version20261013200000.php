<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import and export: notes.external_id, unique per owner.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notes ADD external_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_notes_owner_external_id ON notes (owner_id, external_id)');
    }

    public function down(Schema $schema): void
    {
        // Notes stay; they can no longer be matched to rows of a later import.
        $this->addSql('DROP INDEX uniq_notes_owner_external_id ON notes');
        $this->addSql('ALTER TABLE notes DROP external_id');
    }
}
