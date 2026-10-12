<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password reset and account settings: one-time account tokens, throttle counters, user time zone and token version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_tokens (id VARCHAR(36) NOT NULL, purpose VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, payload VARCHAR(190) DEFAULT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id VARCHAR(36) NOT NULL, INDEX IDX_8D6EDFFDA76ED395 (user_id), INDEX idx_account_tokens_user_purpose (user_id, purpose), UNIQUE INDEX uniq_account_tokens_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE throttle_hits (id BIGINT AUTO_INCREMENT NOT NULL, bucket VARCHAR(64) NOT NULL, key_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_throttle_hits_lookup (bucket, key_hash, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_tokens ADD CONSTRAINT FK_8D6EDFFDA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        // Existing users get UTC and version 0, which their current tokens (without a version claim) match.
        $this->addSql("ALTER TABLE users ADD timezone VARCHAR(64) DEFAULT 'UTC' NOT NULL, ADD token_version INT DEFAULT 0 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Pending reset and email-change links stop working; passwords and emails are untouched.
        $this->addSql('ALTER TABLE account_tokens DROP FOREIGN KEY FK_8D6EDFFDA76ED395');
        $this->addSql('DROP TABLE account_tokens');
        $this->addSql('DROP TABLE throttle_hits');
        $this->addSql('ALTER TABLE users DROP timezone, DROP token_version');
    }
}
