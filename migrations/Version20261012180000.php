<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes sharing: note_shares (one share per note and user) and append-only audit_events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE note_shares (id VARCHAR(36) NOT NULL, role VARCHAR(16) NOT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, note_id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, INDEX IDX_888D26A826ED0855 (note_id), INDEX idx_note_shares_user (user_id), UNIQUE INDEX uniq_note_shares_note_user (note_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_events (id VARCHAR(36) NOT NULL, event_type VARCHAR(64) NOT NULL, actor_id VARCHAR(36) DEFAULT NULL, note_id VARCHAR(36) NOT NULL, share_id VARCHAR(36) DEFAULT NULL, target_user_id VARCHAR(36) DEFAULT NULL, metadata JSON NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_audit_events_note_created (note_id, created_at), INDEX idx_audit_events_actor (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE note_shares ADD CONSTRAINT FK_888D26A826ED0855 FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_shares ADD CONSTRAINT FK_888D26A8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drops all shares and audit history; existing notes and users are untouched.
        $this->addSql('ALTER TABLE note_shares DROP FOREIGN KEY FK_888D26A826ED0855');
        $this->addSql('ALTER TABLE note_shares DROP FOREIGN KEY FK_888D26A8A76ED395');
        $this->addSql('DROP TABLE note_shares');
        $this->addSql('DROP TABLE audit_events');
    }
}
