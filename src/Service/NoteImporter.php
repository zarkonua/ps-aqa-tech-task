<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Category;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\RealtimeEvent;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\TaxonomyRepository;
use App\Util\TaxonomyName;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks rows of an import against the rules for notes and, when asked to, applies them.
 * Everything imported belongs to the importing user: notes, and any tags or categories that have to be created.
 */
final class NoteImporter
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MODES = ['atomic', 'partial'];
    public const DUPLICATE_POLICIES = ['reject', 'skip', 'upsert'];

    private const TAXONOMY_LIMIT = 100;
    private const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,63}$/D';
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NoteRepository $notes,
        private readonly TaxonomyRepository $taxonomy,
        private readonly NoteRevisions $revisions,
        private readonly NoteMutator $mutator,
        private readonly AuditLogger $audit,
        private readonly Realtime $realtime,
    ) {
    }

    /**
     * Decides for every row what importing it would do, without changing anything.
     *
     * @param list<array{line: int, values: array<string, mixed>}>   $rows
     * @param list<array{line: int, field: string, message: string}> $errors errors already found while reading the file
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, field: string, message: string}>}
     */
    public function plan(User $user, array $rows, array $errors, string $duplicates): array
    {
        $existingTags = $this->namesInUse(Tag::class, $user);
        $existingCategories = $this->namesInUse(Category::class, $user);
        $newTags = [];
        $newCategories = [];
        $seenExternalIds = [];
        $planned = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            $rowErrors = [];
            $fail = static function (string $field, string $message) use (&$rowErrors, $line): void {
                $rowErrors[] = ['line' => $line, 'field' => $field, 'message' => $message];
            };

            $title = $values['title'] ?? null;
            $title = is_string($title) ? trim($title) : $title;
            if (!is_string($title) || '' === $title) {
                $fail('title', is_string($title) || null === $title ? 'Title is required.' : 'Title must be text.');
            } elseif (mb_strlen($title) > 255) {
                $fail('title', 'Title cannot be longer than 255 characters.');
            }

            $content = $values['content'] ?? null;
            if (!is_string($content) || '' === trim($content)) {
                $fail('content', is_string($content) || null === $content ? 'Content is required.' : 'Content must be text.');
            } elseif (mb_strlen($content) > 10000) {
                $fail('content', 'Content cannot be longer than 10000 characters.');
            }

            $externalId = $values['external_id'] ?? null;
            $externalId = '' === $externalId ? null : $externalId;
            if (null !== $externalId && (!is_string($externalId) || 1 !== preg_match(self::EXTERNAL_ID_PATTERN, $externalId))) {
                $fail('external_id', 'External ID must be 1 to 64 letters, digits, dots, dashes, underscores or colons, starting with a letter or digit.');
                $externalId = null;
            } elseif (null !== $externalId && isset($seenExternalIds[$externalId])) {
                $fail('external_id', sprintf('External ID "%s" is already used on line %d of this file.', $externalId, $seenExternalIds[$externalId]));
            } elseif (null !== $externalId) {
                $seenExternalIds[$externalId] = $line;
            }

            $tags = $values['tags'] ?? [];
            $tags = is_string($tags) ? NoteExchangeFormat::splitTags($tags) : $tags;
            $tagNames = [];
            if (null === $tags) {
                $tags = [];
            }
            if (!is_array($tags) || !array_is_list($tags)) {
                $fail('tags', 'Tags must be a list of names.');
            } else {
                foreach ($tags as $tag) {
                    $name = is_string($tag) ? TaxonomyName::normalize($tag) : '';
                    if ('' === $name || TaxonomyName::length($name) > TaxonomyName::MAX_LENGTH) {
                        $fail('tags', 'Each tag must be a name of 1 to 30 characters.');
                        continue;
                    }
                    $tagNames[TaxonomyName::key($name)] = $name;
                }
                if (count($tagNames) > NoteMutator::MAX_TAGS_PER_NOTE) {
                    $fail('tags', sprintf('A note can have at most %d tags.', NoteMutator::MAX_TAGS_PER_NOTE));
                }
            }

            $category = $values['category'] ?? null;
            $category = '' === $category ? null : $category;
            if (null !== $category) {
                $category = is_string($category) ? TaxonomyName::normalize($category) : '';
                if ('' === $category || TaxonomyName::length($category) > TaxonomyName::MAX_LENGTH) {
                    $fail('category', 'Category must be a name of 1 to 30 characters.');
                    $category = null;
                }
            }

            foreach (['created_at', 'updated_at'] as $field) {
                $timestamp = $values[$field] ?? null;
                if (null !== $timestamp && '' !== $timestamp && (!is_string($timestamp) || 1 !== preg_match(self::TIMESTAMP_PATTERN, $timestamp))) {
                    $fail($field, 'Must be an ISO 8601 date-time with a time zone, e.g. 2026-10-11T08:30:00Z, or empty.');
                }
            }

            // Tags and categories that do not exist yet would be created; the user's limits still apply.
            if ([] === $rowErrors) {
                foreach (array_keys($tagNames) as $key) {
                    if (!isset($existingTags[$key])) {
                        $newTags[$key] = true;
                    }
                }
                if (null !== $category && !isset($existingCategories[TaxonomyName::key($category)])) {
                    $newCategories[TaxonomyName::key($category)] = true;
                }
                if (count($existingTags) + count($newTags) > self::TAXONOMY_LIMIT) {
                    $fail('tags', sprintf('Importing this row would take you over the limit of %d tags.', self::TAXONOMY_LIMIT));
                }
                if (count($existingCategories) + count($newCategories) > self::TAXONOMY_LIMIT) {
                    $fail('category', sprintf('Importing this row would take you over the limit of %d categories.', self::TAXONOMY_LIMIT));
                }
            }

            $action = 'create';
            $existing = null;
            if ([] === $rowErrors && null !== $externalId) {
                $existing = $this->notes->findOneBy(['owner' => $user, 'externalId' => $externalId]);
                if (null !== $existing) {
                    if ('reject' === $duplicates) {
                        $fail('external_id', sprintf('You already have a note with the external ID "%s".', $externalId));
                    } else {
                        $action = 'upsert' === $duplicates ? 'update' : 'skip';
                    }
                }
            }

            if ([] !== $rowErrors) {
                array_push($errors, ...$rowErrors);
                continue;
            }

            $planned[] = [
                'line' => $line,
                'action' => $action,
                'note_id' => $existing?->getId(),
                'external_id' => $externalId,
                'title' => $title,
                'content' => $content,
                'tags' => array_values($tagNames),
                'category' => $category,
            ];
        }

        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return ['rows' => $planned, 'errors' => $errors];
    }

    /**
     * Applies planned rows in one transaction.
     *
     * @param list<array<string, mixed>> $rows from plan()
     *
     * @return list<array{line: int, action: string, id: string|null}>
     */
    public function apply(User $user, array $rows): array
    {
        $updatedNotes = [];
        $results = $this->entityManager->wrapInTransaction(function () use ($user, $rows, &$updatedNotes): array {
            $tags = $this->byKey(Tag::class, $user);
            $categories = $this->byKey(Category::class, $user);
            $results = [];

            foreach ($rows as $row) {
                if ('skip' === $row['action']) {
                    $results[] = ['line' => $row['line'], 'action' => 'skipped', 'id' => $row['note_id']];
                    continue;
                }

                $noteTags = [];
                foreach ($row['tags'] as $name) {
                    $noteTags[] = $tags[TaxonomyName::key($name)] ??= $this->createTaxonomyItem(Tag::class, $user, $name);
                }
                $category = null === $row['category'] ? null : ($categories[TaxonomyName::key($row['category'])] ??= $this->createTaxonomyItem(Category::class, $user, $row['category']));

                if ('create' === $row['action']) {
                    $note = (new Note())->setOwner($user)->setTitle($row['title'])->setContent($row['content'])->setExternalId($row['external_id']);
                    $note->replaceTags($noteTags);
                    $note->setCategory($category);
                    $this->entityManager->persist($note);
                    $this->entityManager->flush();
                    $this->revisions->record($note, NoteRevision::CREATED, $user);
                    $this->audit->record(AuditEvent::NOTE_CREATED, $user, (string) $note->getId(), metadata: ['title' => ['length_after' => mb_strlen($note->getTitle())], 'content' => ['length_after' => mb_strlen($note->getContent())], 'version' => 1, 'imported' => true]);
                    $results[] = ['line' => $row['line'], 'action' => 'created', 'id' => (string) $note->getId()];
                    continue;
                }

                // Update of the user's own note with this external ID: the same rules as an edit in the app.
                $note = $this->notes->find($row['note_id']);
                $changed = [];
                foreach (['title' => [$note->getTitle(), $row['title']], 'content' => [$note->getContent(), $row['content']]] as $field => [$before, $after]) {
                    if ($before !== $after) {
                        $changed['changed'][] = $field;
                        $changed[$field] = ['length_before' => mb_strlen($before), 'length_after' => mb_strlen($after)];
                    }
                }
                if ([] !== $changed) {
                    $from = $note->getVersion();
                    while (!$this->revisions->claimNext($note, $from)) {
                        $from = $this->revisions->currentVersion($note);
                    }
                    $note->setVersion($from + 1)->setTitle($row['title'])->setContent($row['content']);
                    $this->revisions->record($note, NoteRevision::UPDATED, $user);
                    $this->audit->record(AuditEvent::NOTE_UPDATED, $user, (string) $note->getId(), metadata: $changed + ['version' => $note->getVersion(), 'imported' => true]);
                    $updatedNotes[] = $note;
                }
                $this->entityManager->flush();
                $this->mutator->changeTags($note, $user, static fn (): array => $noteTags);
                $this->mutator->setCategory($note, $user, $category);
                $results[] = ['line' => $row['line'], 'action' => 'updated', 'id' => (string) $note->getId()];
            }
            $this->entityManager->flush();

            return $results;
        });

        // Only once everything is committed.
        foreach ($updatedNotes as $note) {
            $this->realtime->publish((string) $note->getId(), RealtimeEvent::NOTE_UPDATED, ['version' => $note->getVersion(), 'actor' => ['id' => (string) $user->getId(), 'email' => $user->getEmail()]]);
        }

        return $results;
    }

    /**
     * @param class-string<Tag|Category> $class
     *
     * @return array<string, true> name keys the user already has
     */
    private function namesInUse(string $class, User $user): array
    {
        return array_fill_keys(array_keys($this->byKey($class, $user)), true);
    }

    /**
     * @template T of Tag|Category
     *
     * @param class-string<T> $class
     *
     * @return array<string, T>
     */
    private function byKey(string $class, User $user): array
    {
        $items = [];
        foreach ($this->taxonomy->findPage($class, $user, null, 1, self::TAXONOMY_LIMIT) as $item) {
            $items[$item->getNameKey()] = $item;
        }

        return $items;
    }

    /**
     * @template T of Tag|Category
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function createTaxonomyItem(string $class, User $user, string $name): Tag|Category
    {
        $item = new $class($user, $name);
        $this->entityManager->persist($item);
        $this->audit->record(Tag::class === $class ? AuditEvent::TAG_CREATED : AuditEvent::CATEGORY_CREATED, $user, null, metadata: ['imported' => true], subjectId: $item->getId());

        return $item;
    }
}
