<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditEventRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit record. References are stored as plain IDs (no foreign keys)
 * so that records outlive the notes, shares and users they describe.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Table(name: 'audit_events')]
#[ORM\Index(name: 'idx_audit_events_note_created', columns: ['note_id', 'created_at'])]
#[ORM\Index(name: 'idx_audit_events_actor', columns: ['actor_id'])]
class AuditEvent
{
    public const SHARE_GRANTED = 'share.granted';
    public const SHARE_ROLE_CHANGED = 'share.role_changed';
    public const SHARE_REVOKED = 'share.revoked';
    public const SHARE_REMOVED_WITH_NOTE = 'share.removed_with_note';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'event_type', length: 64)]
    private string $eventType;

    #[ORM\Column(name: 'actor_id', length: 36, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(name: 'note_id', length: 36)]
    private string $noteId;

    #[ORM\Column(name: 'share_id', length: 36, nullable: true)]
    private ?string $shareId;

    #[ORM\Column(name: 'target_user_id', length: 36, nullable: true)]
    private ?string $targetUserId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(name: 'correlation_id', length: 64, nullable: true)]
    private ?string $correlationId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $eventType,
        ?string $actorId,
        string $noteId,
        ?string $shareId = null,
        ?string $targetUserId = null,
        array $metadata = [],
        ?string $correlationId = null,
    ) {
        $this->id = Uuid::v4();
        $this->eventType = $eventType;
        $this->actorId = $actorId;
        $this->noteId = $noteId;
        $this->shareId = $shareId;
        $this->targetUserId = $targetUserId;
        $this->metadata = $metadata;
        $this->correlationId = $correlationId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getNoteId(): string
    {
        return $this->noteId;
    }

    public function getShareId(): ?string
    {
        return $this->shareId;
    }

    public function getTargetUserId(): ?string
    {
        return $this->targetUserId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
