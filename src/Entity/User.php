<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ApiResource(operations: [])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private ?string $id = null;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'is_verified', type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** IANA time zone the user wants dates shown in. */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    /** May read the full audit feed. Granted from the command line only. */
    #[ORM\Column(name: 'is_admin', type: 'boolean', options: ['default' => false])]
    private bool $isAdmin = false;

    /**
     * Notification types the user does not want emails for (type => true). Null: emails for all.
     *
     * @var array<string, bool>|null
     */
    #[ORM\Column(name: 'email_opt_outs', type: 'json', nullable: true)]
    private ?array $emailOptOuts = null;

    /** Raised whenever the password changes; tokens issued for an older value are rejected. */
    #[ORM\Column(name: 'token_version', type: 'integer', options: ['default' => 0])]
    private int $tokenVersion = 0;

    /** @var Collection<int, SignupCode> */
    #[ORM\OneToMany(targetEntity: SignupCode::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $signupCodes;

    /** @var Collection<int, AuthToken> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: AuthToken::class, orphanRemoval: true)]
    private Collection $authTokens;

    /** @var Collection<int, Note> */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Note::class, orphanRemoval: true)]
    private Collection $notes;

    public function __construct()
    {
        $this->id = self::generateUuidV4();
        $this->createdAt = new \DateTimeImmutable();
        $this->signupCodes = new ArrayCollection();
        $this->authTokens = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): self
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * @return array<string, bool> notification type => email wanted; types not listed are wanted
     */
    public function getEmailOptOuts(): array
    {
        return $this->emailOptOuts ?? [];
    }

    public function wantsEmailFor(string $notificationType): bool
    {
        return !($this->emailOptOuts[$notificationType] ?? false);
    }

    public function setWantsEmailFor(string $notificationType, bool $wanted): self
    {
        $optOuts = $this->emailOptOuts ?? [];
        if ($wanted) {
            unset($optOuts[$notificationType]);
        } else {
            $optOuts[$notificationType] = true;
        }
        $this->emailOptOuts = [] === $optOuts ? null : $optOuts;

        return $this;
    }

    public function getTokenVersion(): int
    {
        return $this->tokenVersion;
    }

    /**
     * Signs the user out everywhere: every JWT issued so far stops working.
     */
    public function invalidateTokens(): self
    {
        ++$this->tokenVersion;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->isAdmin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function setIsAdmin(bool $isAdmin): self
    {
        $this->isAdmin = $isAdmin;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
