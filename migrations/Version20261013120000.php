<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tasks and kanban board: tasks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tasks (id VARCHAR(36) NOT NULL, title VARCHAR(150) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(16) NOT NULL, priority VARCHAR(16) NOT NULL, due_at DATETIME DEFAULT NULL, position INT NOT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id VARCHAR(36) NOT NULL, assignee_id VARCHAR(36) DEFAULT NULL, note_id VARCHAR(36) DEFAULT NULL, INDEX IDX_505865977E3C61F9 (owner_id), INDEX IDX_5058659726ED0855 (note_id), INDEX idx_tasks_owner_status_position (owner_id, status, position), INDEX idx_tasks_assignee (assignee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_505865977E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_5058659759EC7D60 FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_5058659726ED0855 FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Deletes all tasks; notes and users are untouched.
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_505865977E3C61F9');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_5058659759EC7D60');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_5058659726ED0855');
        $this->addSql('DROP TABLE tasks');
    }
}
