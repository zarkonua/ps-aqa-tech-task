<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\SortFilter;
use App\ApiPlatform\NoteAccessExtension;
use App\ApiPlatform\NoteTaxonomyFilterExtension;
use App\Repository\NoteRepository;
use App\State\NoteCollectionProvider;
use App\State\NotePersistProcessor;
use App\State\NoteRemoveProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/api/notes',
            paginationItemsPerPage: 20,
            paginationMaximumItemsPerPage: NoteCollectionProvider::MAX_LIMIT,
            paginationClientItemsPerPage: true,
            security: "is_granted('ROLE_USER')",
            provider: NoteCollectionProvider::class,
            parameters: [
                'q' => new QueryParameter(
                    filter: new FreeTextQueryFilter(new OrFilter(new PartialSearchFilter())),
                    properties: ['title', 'content'],
                    schema: ['type' => 'string', 'maxLength' => 200],
                    description: 'Text to find in the title or the content. Case-insensitive; "%" and "_" are taken literally.',
                    constraints: [new Assert\Sequentially([
                        new Assert\Type(type: 'string', message: 'q must be a string.'),
                        new Assert\Length(max: 200, maxMessage: 'q cannot be longer than {{ limit }} characters.'),
                    ])],
                ),
                'updatedFrom' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date-time', 'example' => '2026-10-01'],
                    description: 'Only notes updated at or after this moment (inclusive). ISO 8601 date (whole UTC day) or date-time with offset. Malformed: 400.',
                ),
                'updatedTo' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date-time', 'example' => '2026-10-31'],
                    description: 'Only notes updated at or before this moment (inclusive). Earlier than "updatedFrom": 422.',
                ),
                'sortBy' => new QueryParameter(
                    // No "enum" in the schema: it would turn an unknown value into a 422, and the contract says 400.
                    schema: ['type' => 'string', 'default' => 'updatedAt', 'example' => 'title'],
                    description: 'Sort field: "title", "createdAt" or "updatedAt". Notes with equal values are ordered by ID. Unknown value: 400.',
                ),
                'order' => new QueryParameter(
                    schema: ['type' => 'string', 'example' => 'asc'],
                    description: 'Sort direction: "asc" or "desc". Default: "desc" for dates, "asc" for title. Unknown value: 400.',
                ),
                'limit' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => NoteCollectionProvider::MAX_LIMIT, 'default' => 20],
                    description: 'Page size. Out of range: 422. "itemsPerPage" is accepted as an older name.',
                ),
                'title' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title'),
                'content' => new QueryParameter(filter: new PartialSearchFilter(), property: 'content'),
                'sort[updatedAt]' => new QueryParameter(filter: new SortFilter(), property: 'updatedAt'),
                'sort[title]' => new QueryParameter(filter: new SortFilter(), property: 'title'),
                'scope' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => NoteAccessExtension::SCOPES, 'default' => NoteAccessExtension::SCOPE_OWNED],
                    description: 'Which notes to list: "owned" (default), "shared" (shared with me) or "all".',
                    constraints: [new Assert\Choice(choices: NoteAccessExtension::SCOPES, message: 'Scope must be one of: owned, shared, all.')],
                ),
                'tagIds' => new QueryParameter(
                    schema: ['type' => 'string', 'example' => '0b0f6f0e-3c5e-4e63-9f0a-6f2f0d0c1a11,5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10'],
                    description: 'Comma-separated tag IDs (1 to 10). Only notes carrying these tags are listed; see "tagMatch".',
                    constraints: [new Assert\Sequentially([
                        new Assert\Type(type: 'string', message: 'tagIds must be a comma-separated list of tag IDs.'),
                        new Assert\Regex(pattern: NoteTaxonomyFilterExtension::TAG_IDS_PATTERN, message: 'tagIds must be a comma-separated list of 1 to 10 tag IDs.'),
                    ])],
                ),
                'tagMatch' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => NoteTaxonomyFilterExtension::TAG_MATCHES, 'default' => NoteTaxonomyFilterExtension::MATCH_ANY],
                    description: 'With "tagIds": "any" (default) lists notes having at least one of the tags, "all" lists notes having every tag.',
                    constraints: [new Assert\Choice(choices: NoteTaxonomyFilterExtension::TAG_MATCHES, message: 'tagMatch must be one of: any, all.')],
                ),
                'categoryId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only notes in this category are listed.',
                    constraints: [new Assert\Sequentially([
                        new Assert\Type(type: 'string', message: 'categoryId must be a category ID.'),
                        new Assert\Regex(pattern: NoteTaxonomyFilterExtension::ID_PATTERN, message: 'categoryId must be a category ID.'),
                    ])],
                ),
            ],
        ),
        new Post(uriTemplate: '/api/notes', security: "is_granted('ROLE_USER')", processor: NotePersistProcessor::class),
        new Get(uriTemplate: '/api/notes/{id}', security: "is_granted('ROLE_USER')"),
        new Put(
            uriTemplate: '/api/notes/{id}',
            security: "is_granted('NOTE_EDIT', object)",
            securityMessage: 'Your role does not allow editing this note.',
            processor: NotePersistProcessor::class,
        ),
        new Patch(
            uriTemplate: '/api/notes/{id}',
            security: "is_granted('NOTE_EDIT', object)",
            securityMessage: 'Your role does not allow editing this note.',
            processor: NotePersistProcessor::class,
            description: 'Changes the title and/or the content. Requires an If-Match header with the version (ETag) the change is based on: 428 without it, 412 when the note has changed since.',
        ),
        new Delete(
            uriTemplate: '/api/notes/{id}',
            security: "is_granted('NOTE_DELETE', object)",
            securityMessage: 'Only the note owner can delete this note.',
            processor: NoteRemoveProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['note:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['note:write']],
)]
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'notes')]
#[ORM\HasLifecycleCallbacks]
class Note
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[Groups(['note:read'])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    #[Groups(['note:read', 'note:write'])]
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(
        min: 1,
        max: 255,
        minMessage: 'Title cannot be empty.',
        maxMessage: 'Title cannot be longer than {{ limit }} characters.',
    )]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Groups(['note:read', 'note:write'])]
    #[Assert\NotBlank(message: 'Content is required.')]
    #[Assert\Length(
        min: 1,
        max: 10000,
        minMessage: 'Content cannot be empty.',
        maxMessage: 'Content cannot be longer than {{ limit }} characters.',
    )]
    private string $content = '';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['note:read'])]
    #[SerializedName('created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['note:read'])]
    #[SerializedName('updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** Number of the latest revision. Moves only when title or content change. */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[Groups(['note:read'])]
    #[ApiProperty(description: 'Revision number of the note; also sent as the ETag. Send it back in If-Match to avoid overwriting newer changes.', schema: ['type' => 'integer', 'minimum' => 1, 'readOnly' => true])]
    private int $version = 1;

    /** The current user's role on this note; not persisted, set when the note is serialized. */
    #[Groups(['note:read'])]
    #[ApiProperty(
        description: 'Role of the authenticated user on this note.',
        schema: ['type' => 'string', 'enum' => ['owner', 'editor', 'viewer'], 'readOnly' => true],
    )]
    private ?string $role = null;

    /** Taken from the note owner's categories. */
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['note:read'])]
    private ?Category $category = null;

    /**
     * Taken from the note owner's tags.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'note_tags')]
    #[ORM\JoinColumn(name: 'note_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['nameKey' => 'ASC'])]
    #[Groups(['note:read'])]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (null === $this->id) {
            $this->id = self::generateUuidV4();
        }

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    #[Groups(['note:read'])]
    #[SerializedName('owner_id')]
    public function getOwnerId(): ?string
    {
        return $this->owner?->getId();
    }

    #[Groups(['note:read'])]
    #[SerializedName('owner_email')]
    public function getOwnerEmail(): ?string
    {
        return $this->owner?->getEmail();
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * @param iterable<Tag> $tags
     */
    public function replaceTags(iterable $tags): self
    {
        $wanted = [];
        foreach ($tags as $tag) {
            $wanted[$tag->getId()] = $tag;
        }

        foreach ($this->tags as $tag) {
            if (!isset($wanted[$tag->getId()])) {
                $this->tags->removeElement($tag);
            }
            unset($wanted[$tag->getId()]);
        }

        foreach ($wanted as $tag) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * An update keeps the creation time, and a save that changes nothing keeps the update time too.
     */
    public function keepTimestampsOf(self $stored, bool $includingUpdate): self
    {
        $this->createdAt = $stored->createdAt;
        if ($includingUpdate) {
            $this->updatedAt = $stored->updatedAt;
        }

        return $this;
    }

    /**
     * Tags and category are not part of the note payload: an update must leave them as they are.
     */
    public function keepTaxonomyOf(self $stored): self
    {
        $this->category = $stored->category;
        $this->tags = $stored->tags;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
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
