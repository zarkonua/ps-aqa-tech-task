<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tags and categories: per-user tags and categories, note_tags, notes.category_id; audit events may describe a tag or category.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categories (id VARCHAR(36) NOT NULL, name VARCHAR(30) NOT NULL, name_key VARCHAR(64) NOT NULL COLLATE `utf8mb4_bin`, color VARCHAR(7) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id VARCHAR(36) NOT NULL, INDEX IDX_3AF346687E3C61F9 (owner_id), UNIQUE INDEX uniq_categories_owner_name (owner_id, name_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tags (id VARCHAR(36) NOT NULL, name VARCHAR(30) NOT NULL, name_key VARCHAR(64) NOT NULL COLLATE `utf8mb4_bin`, color VARCHAR(7) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id VARCHAR(36) NOT NULL, INDEX IDX_6FBC94267E3C61F9 (owner_id), UNIQUE INDEX uniq_tags_owner_name (owner_id, name_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note_tags (note_id VARCHAR(36) NOT NULL, tag_id VARCHAR(36) NOT NULL, INDEX IDX_CFC6857E26ED0855 (note_id), INDEX IDX_CFC6857EBAD26311 (tag_id), PRIMARY KEY (note_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF346687E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_6FBC94267E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_tags ADD CONSTRAINT FK_CFC6857E26ED0855 FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_tags ADD CONSTRAINT FK_CFC6857EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_events ADD subject_id VARCHAR(36) DEFAULT NULL, CHANGE note_id note_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE notes ADD category_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE notes ADD CONSTRAINT FK_11BA68C12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_11BA68C12469DE2 ON notes (category_id)');
    }

    public function down(Schema $schema): void
    {
        // Drops all tags, categories and their links to notes, and the audit records about them
        // (those have no note and would violate the restored NOT NULL). Notes themselves are kept.
        $this->addSql('ALTER TABLE notes DROP FOREIGN KEY FK_11BA68C12469DE2');
        $this->addSql('DROP INDEX IDX_11BA68C12469DE2 ON notes');
        $this->addSql('ALTER TABLE notes DROP category_id');
        $this->addSql('DELETE FROM audit_events WHERE note_id IS NULL');
        $this->addSql('ALTER TABLE audit_events DROP subject_id, CHANGE note_id note_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE note_tags DROP FOREIGN KEY FK_CFC6857E26ED0855');
        $this->addSql('ALTER TABLE note_tags DROP FOREIGN KEY FK_CFC6857EBAD26311');
        $this->addSql('DROP TABLE note_tags');
        $this->addSql('ALTER TABLE tags DROP FOREIGN KEY FK_6FBC94267E3C61F9');
        $this->addSql('DROP TABLE tags');
        $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_3AF346687E3C61F9');
        $this->addSql('DROP TABLE categories');
    }
}
