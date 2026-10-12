<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareRole;
use App\Repository\NoteShareRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteShareRepository::class)]
#[ORM\Table(name: 'note_shares')]
#[ORM\UniqueConstraint(name: 'uniq_note_shares_note_user', columns: ['note_id', 'user_id'])]
#[ORM\Index(name: 'idx_note_shares_user', columns: ['user_id'])]
class NoteShare
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Note $note;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: ShareRole::class)]
    private ShareRole $role;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Note $note, User $user, ShareRole $role, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v4();
        $this->note = $note;
        $this->user = $user;
        $this->role = $role;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNote(): Note
    {
        return $this->note;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): ShareRole
    {
        return $this->role;
    }

    public function setRole(ShareRole $role): self
    {
        $this->role = $role;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
