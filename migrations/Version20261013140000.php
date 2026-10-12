<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications: notifications and per-user email opt-outs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notifications (id VARCHAR(36) NOT NULL, type VARCHAR(32) NOT NULL, event_id VARCHAR(64) NOT NULL, actor_id VARCHAR(36) DEFAULT NULL, resource_type VARCHAR(16) NOT NULL, resource_id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, user_id VARCHAR(36) NOT NULL, INDEX IDX_6000B0D3A76ED395 (user_id), INDEX idx_notifications_user_read_created (user_id, read_at, created_at), INDEX idx_notifications_created (created_at), UNIQUE INDEX uniq_notifications_user_event (user_id, event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        // NULL means "emails for every notification type", which is what existing users get.
        $this->addSql('ALTER TABLE users ADD email_opt_outs JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Deletes all notifications and email preferences.
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A76ED395');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('ALTER TABLE users DROP email_opt_outs');
    }
}
