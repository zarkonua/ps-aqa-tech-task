<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Realtime collaboration: short-lived event stream per note and presence of its readers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE realtime_events (id BIGINT AUTO_INCREMENT NOT NULL, public_id VARCHAR(32) NOT NULL, note_id VARCHAR(36) NOT NULL, type VARCHAR(32) NOT NULL, payload JSON NOT NULL, only_for_user_id VARCHAR(36) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_realtime_events_public_id (public_id), INDEX idx_realtime_events_note (note_id, id), INDEX idx_realtime_events_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note_presence (note_id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, last_seen_at DATETIME NOT NULL, INDEX idx_note_presence_seen (last_seen_at), PRIMARY KEY (note_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // Both tables hold only transient data.
        $this->addSql('DROP TABLE realtime_events');
        $this->addSql('DROP TABLE note_presence');
    }
}
