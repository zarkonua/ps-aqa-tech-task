<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something that happened and concerns one user. The same event (same event ID)
 * is stored at most once per user, however often it is delivered.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\UniqueConstraint(name: 'uniq_notifications_user_event', columns: ['user_id', 'event_id'])]
#[ORM\Index(name: 'idx_notifications_user_read_created', columns: ['user_id', 'read_at', 'created_at'])]
#[ORM\Index(name: 'idx_notifications_created', columns: ['created_at'])]
class Notification
{
    public const SHARE_GRANTED = 'share_granted';
    public const SHARE_REVOKED = 'share_revoked';
    public const TASK_ASSIGNED = 'task_assigned';
    public const TASK_DUE = 'task_due';
    public const TASK_COMPLETED = 'task_completed';
    public const TYPES = [self::SHARE_GRANTED, self::SHARE_REVOKED, self::TASK_ASSIGNED, self::TASK_DUE, self::TASK_COMPLETED];

    public const RESOURCE_NOTE = 'note';
    public const RESOURCE_TASK = 'task';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(name: 'event_id', length: 64)]
    private string $eventId;

    #[ORM\Column(name: 'actor_id', length: 36, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(name: 'resource_type', length: 16)]
    private string $resourceType;

    #[ORM\Column(name: 'resource_id', length: 36)]
    private string $resourceId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    private function __construct()
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
