<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something that happened to a note, kept for a short while so that open streams
 * and reconnecting clients can be told. Rows are written and read with plain SQL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'realtime_events')]
#[ORM\UniqueConstraint(name: 'uniq_realtime_events_public_id', columns: ['public_id'])]
#[ORM\Index(name: 'idx_realtime_events_note', columns: ['note_id', 'id'])]
#[ORM\Index(name: 'idx_realtime_events_created', columns: ['created_at'])]
class RealtimeEvent
{
    public const NOTE_UPDATED = 'note.updated';
    public const NOTE_DELETED = 'note.deleted';
    public const SHARE_REVOKED = 'share.revoked';
    public const PRESENCE_JOINED = 'presence.joined';
    public const PRESENCE_LEFT = 'presence.left';

    /** Internal order of events. Never shown to clients. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    /** The ID clients see: random, so it says nothing about how many events there are or their order. */
    #[ORM\Column(name: 'public_id', length: 32)]
    private string $publicId = '';

    #[ORM\Column(name: 'note_id', length: 36)]
    private string $noteId = '';

    #[ORM\Column(length: 32)]
    private string $type = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /** When set, only this user's streams get the event. */
    #[ORM\Column(name: 'only_for_user_id', length: 36, nullable: true)]
    private ?string $onlyForUserId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
