<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NoteRevisionRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * Title and content of a note as saved at one revision. Never changed afterwards.
 */
#[ORM\Entity(repositoryClass: NoteRevisionRepository::class)]
#[ORM\Table(name: 'note_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_note_revisions_note_revision', columns: ['note_id', 'revision'])]
class NoteRevision
{
    public const CREATED = 'create';
    public const UPDATED = 'update';
    public const RESTORED = 'restore';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Note $note;

    #[ORM\Column(type: 'integer')]
    private int $revision;

    #[ORM\Column(length: 16)]
    private string $kind;

    /** For a restore: the revision whose text was brought back. */
    #[ORM\Column(name: 'restored_from', type: 'integer', nullable: true)]
    private ?int $restoredFrom;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content;

    /** Kept as a plain ID: history outlives the account that wrote it. */
    #[ORM\Column(name: 'actor_id', length: 36, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Note $note, int $revision, string $kind, ?string $actorId, ?int $restoredFrom = null, ?\DateTimeImmutable $createdAt = null)
    {
        $this->id = Uuid::v4();
        $this->note = $note;
        $this->revision = $revision;
        $this->kind = $kind;
        $this->restoredFrom = $restoredFrom;
        $this->title = $note->getTitle();
        $this->content = $note->getContent();
        $this->actorId = $actorId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNote(): Note
    {
        return $this->note;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getRestoredFrom(): ?int
    {
        return $this->restoredFrom;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
