<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountTokenRepository;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * One-time token mailed to a user (password reset, email change).
 * Only the SHA-256 hash is stored; the token itself exists in the email alone.
 */
#[ORM\Entity(repositoryClass: AccountTokenRepository::class)]
#[ORM\Table(name: 'account_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_account_tokens_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_account_tokens_user_purpose', columns: ['user_id', 'purpose'])]
class AccountToken
{
    public const PASSWORD_RESET = 'password_reset';
    public const EMAIL_CHANGE = 'email_change';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $purpose;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    /** For an email change: the address to switch to. */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $payload;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $purpose, string $plainToken, \DateTimeImmutable $expiresAt, ?string $payload = null)
    {
        $this->id = Uuid::v4();
        $this->user = $user;
        $this->purpose = $purpose;
        $this->tokenHash = self::hash($plainToken);
        $this->payload = $payload;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt < ($now ?? new \DateTimeImmutable());
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(?\DateTimeImmutable $at = null): self
    {
        $this->usedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
