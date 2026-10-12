<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'File attachments: note_attachments (metadata; the files are on disk under var/storage).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE note_attachments (id VARCHAR(36) NOT NULL, uploaded_by VARCHAR(36) DEFAULT NULL, filename VARCHAR(255) NOT NULL, storage_key VARCHAR(64) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, sha256 VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, note_id VARCHAR(36) NOT NULL, INDEX IDX_617D6D5326ED0855 (note_id), INDEX idx_note_attachments_note_created (note_id, created_at), UNIQUE INDEX uniq_note_attachments_storage_key (storage_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE note_attachments ADD CONSTRAINT FK_617D6D5326ED0855 FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drops the attachment records. The files stay in var/storage until removed by hand
        // or with "app:attachments:prune" after migrating up again.
        $this->addSql('ALTER TABLE note_attachments DROP FOREIGN KEY FK_617D6D5326ED0855');
        $this->addSql('DROP TABLE note_attachments');
    }
}
