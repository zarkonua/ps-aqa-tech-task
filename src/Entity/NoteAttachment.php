<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NoteAttachmentRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteAttachmentRepository::class)]
#[ORM\Table(name: 'note_attachments')]
#[ORM\UniqueConstraint(name: 'uniq_note_attachments_storage_key', columns: ['storage_key'])]
#[ORM\Index(name: 'idx_note_attachments_note_created', columns: ['note_id', 'created_at'])]
class NoteAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Note $note;

    /** Kept as a plain ID: the attachment stays when the uploader's account is gone. */
    #[ORM\Column(name: 'uploaded_by', length: 36, nullable: true)]
    private ?string $uploadedBy;

    /** Sanitized name shown to users; never used as a path. */
    #[ORM\Column(length: 255)]
    private string $filename;

    /** Random name of the file in storage; reveals nothing about the upload. */
    #[ORM\Column(name: 'storage_key', length: 64)]
    private string $storageKey;

    #[ORM\Column(name: 'mime_type', length: 100)]
    private string $mimeType;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Note $note, ?string $uploadedBy, string $filename, string $storageKey, string $mimeType, int $size, string $sha256, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v4();
        $this->note = $note;
        $this->uploadedBy = $uploadedBy;
        $this->filename = $filename;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNote(): Note
    {
        return $this->note;
    }

    public function getUploadedBy(): ?string
    {
        return $this->uploadedBy;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
