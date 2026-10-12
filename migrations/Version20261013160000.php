<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit log: administrator flag on users and a microsecond sequence that gives audit records their exact order.';
    }

    public function up(Schema $schema): void
    {
        // Nobody is an administrator until made one with "app:user:admin".
        $this->addSql('ALTER TABLE users ADD is_admin TINYINT DEFAULT 0 NOT NULL');
        // created_at has one-second resolution; the sequence (microseconds since 1970) orders events within a second.
        // Records that exist already get the start of their second, so their order within it stays undefined.
        $this->addSql('ALTER TABLE audit_events ADD sequence BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE audit_events SET sequence = UNIX_TIMESTAMP(created_at) * 1000000');
        $this->addSql('CREATE INDEX idx_audit_events_sequence ON audit_events (sequence, id)');
    }

    public function down(Schema $schema): void
    {
        // Audit records themselves are kept.
        $this->addSql('DROP INDEX idx_audit_events_sequence ON audit_events');
        $this->addSql('ALTER TABLE audit_events DROP sequence');
        $this->addSql('ALTER TABLE users DROP is_admin');
    }
}
