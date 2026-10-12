<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Note version history: notes.version and note_revisions, with the current text of every note as its revision 1.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE note_revisions (id VARCHAR(36) NOT NULL, revision INT NOT NULL, kind VARCHAR(16) NOT NULL, restored_from INT DEFAULT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, actor_id VARCHAR(36) DEFAULT NULL, created_at DATETIME NOT NULL, note_id VARCHAR(36) NOT NULL, INDEX IDX_9E16C71A26ED0855 (note_id), UNIQUE INDEX uniq_note_revisions_note_revision (note_id, revision), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE note_revisions ADD CONSTRAINT FK_9E16C71A26ED0855 FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notes ADD version INT DEFAULT 1 NOT NULL');
        // Existing notes start their history with what they contain now.
        $this->addSql("INSERT INTO note_revisions (id, note_id, revision, kind, restored_from, title, content, actor_id, created_at) SELECT UUID(), id, 1, 'create', NULL, title, content, owner_id, updated_at FROM notes");
    }

    public function down(Schema $schema): void
    {
        // Drops all history; notes keep their current text.
        $this->addSql('ALTER TABLE note_revisions DROP FOREIGN KEY FK_9E16C71A26ED0855');
        $this->addSql('DROP TABLE note_revisions');
        $this->addSql('ALTER TABLE notes DROP version');
    }
}
