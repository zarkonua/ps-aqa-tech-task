<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\TaxonomyName;
use App\Util\Uuid;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A named, optionally colored label owned by one user. Base of Tag and Category.
 */
#[ORM\MappedSuperclass]
abstract class TaxonomyItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[Groups(['note:read'])]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 30)]
    #[Groups(['note:read'])]
    private string $name;

    /** Lowercased name; compared byte by byte so uniqueness ignores case and nothing else. */
    #[ORM\Column(name: 'name_key', length: 64, options: ['collation' => 'utf8mb4_bin'])]
    private string $nameKey;

    #[ORM\Column(length: 7, nullable: true)]
    #[Groups(['note:read'])]
    private ?string $color;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param string $name already normalized with TaxonomyName::normalize()
     */
    public function __construct(User $owner, string $name, ?string $color = null, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v4();
        $this->owner = $owner;
        $this->name = $name;
        $this->nameKey = TaxonomyName::key($name);
        $this->color = $color;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameKey(): string
    {
        return $this->nameKey;
    }

    /**
     * @param string $name already normalized with TaxonomyName::normalize()
     */
    public function rename(string $name): self
    {
        $this->name = $name;
        $this->nameKey = TaxonomyName::key($name);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
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
