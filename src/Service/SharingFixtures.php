<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountToken;
use App\Entity\AuditEvent;
use App\Entity\Category;
use App\Entity\Note;
use App\Entity\NoteAttachment;
use App\Entity\NoteRevision;
use App\Entity\NoteShare;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\NoteAttachmentRepository;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deterministic personas, notes, shares, tags and categories for exercising sharing, taxonomy and search.
 * Everything is keyed by a run ID: the same run ID always yields the same emails and IDs,
 * and can be purged without touching other runs or real data.
 */
final class SharingFixtures
{
    public const VERSION = 8;
    public const TASKS = 20;
    public const SEARCH_NOTES = 60;
    public const BULK_NOTES = 105;
    public const DEFAULT_PASSWORD = 'Fixture-Passw0rd!';
    /** "unverified" signed up but never confirmed the email; all others can sign in. */
    public const PERSONAS = ['owner', 'viewer', 'editor', 'unrelated', 'unverified'];

    private const RUN_ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    private const NOTES = [
        'private_1' => [],
        'private_2' => [],
        'shared_1' => ['viewer' => ShareRole::Viewer, 'editor' => ShareRole::Editor],
        'shared_2' => ['viewer' => ShareRole::Viewer],
    ];

    /** persona => key => [name, color]. The owner has 11 tags: one more than a note can hold. */
    private const TAGS = [
        'owner' => [
            'work' => ['Work', '#1f77b4'],
            'uber' => ['Über', '#d62728'],
            'japanese' => ['日本語', null],
            'cafe' => ['Café', '#8c564b'],
            'idea' => ['Idea 💡', '#ff7f0e'],
            'urgent' => ['Urgent', '#e377c2'],
            'later' => ['Later', null],
            'home' => ['Home', '#2ca02c'],
            'reading' => ['Reading', null],
            'travel' => ['Travel', '#17becf'],
            'spare' => ['Spare', null],
        ],
        // Same name as the owner's "Work" in another case: allowed, taxonomies are per user.
        'editor' => [
            'work_upper' => ['WORK', null],
        ],
    ];

    private const CATEGORIES = [
        'owner' => [
            'projects' => ['Projects', '#9467bd'],
            'personal' => ['Personal', null],
        ],
        'editor' => [
            'projects_upper' => ['PROJECTS', null],
        ],
    ];

    /** note => [owner tag keys, owner category key]. Notes not listed have no tags and no category. */
    /** Files attached to "shared_1": key => [file name, MIME type, base64 content]. */
    private const ATTACHMENTS = [
        'pdf' => ['report.pdf', 'application/pdf', 'JVBERi0xLjQKMSAwIG9iajw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+ZW5kb2JqCjIgMCBvYmo8PC9UeXBlL1BhZ2VzL0tpZHNbMyAwIFJdL0NvdW50IDE+PmVuZG9iagozIDAgb2JqPDwvVHlwZS9QYWdlL1BhcmVudCAyIDAgUi9NZWRpYUJveFswIDAgMjAwIDIwMF0+PmVuZG9iagp0cmFpbGVyPDwvUm9vdCAxIDAgUj4+CiUlRU9GCg=='],
        'png' => ['pixel.png', 'image/png', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='],
        'txt' => ['нотатки 日本語.txt', 'text/plain', 'VW5pY29kZSBmaWxlIG5hbWUgZml4dHVyZS4K'],
    ];

    /** Title words of the search notes: mixed scripts, case pairs and characters that are special in SQL LIKE. */
    private const SEARCH_WORDS = ['Alpha', 'Ünïcödé', '日本語メモ', 'Привет', 'emoji 🎉', '100% done', 'under_score', "O'Brien; DROP", 'Zeta', 'alpha'];
    private const SEARCH_KEYWORDS = ['apple', 'banana', 'cherry'];
    private const SEARCH_TAGS = ['work', 'idea', 'home', 'travel', 'later'];

    private const NOTE_TAXONOMY = [
        'private_2' => [['work', 'uber', 'japanese', 'cafe', 'idea', 'urgent', 'later', 'home', 'reading', 'travel'], 'projects'],
        'shared_1' => [['work', 'uber'], 'personal'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Throttle $throttle,
        private readonly AttachmentStorage $storage,
        private readonly NoteAttachmentRepository $attachments,
    ) {
    }

    /**
     * Recreates the fixture set for the run from scratch and returns its manifest.
     *
     * @return array<string, mixed>
     */
    public function seed(string $runId, string $password = self::DEFAULT_PASSWORD): array
    {
        $this->purge($runId);

        $manifest = ['version' => self::VERSION, 'run_id' => $runId, 'password' => $password, 'users' => [], 'reset_tokens' => [], 'tags' => [], 'categories' => [], 'notes' => [], 'search_notes' => [], 'bulk_notes' => [], 'tasks' => []];

        $users = [];
        foreach (self::PERSONAS as $persona) {
            $user = (new User())->setEmail(self::email($runId, $persona))->setIsVerified('unverified' !== $persona);
            self::forceId($user, self::id($runId, 'user', $persona));
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $this->entityManager->persist($user);
            $users[$persona] = $user;
            $manifest['users'][$persona] = ['id' => $user->getId(), 'email' => $user->getEmail()];
        }

        // Password reset links that must be refused: one past its expiry, one already used.
        // No working link is ever seeded.
        foreach (['expired' => ['-1 day', null], 'used' => ['+1 day', '-1 hour']] as $state => [$expires, $used]) {
            $plainToken = hash('sha256', sprintf('sharing-fixtures/%s/reset-token/%s', $runId, $state));
            $token = new AccountToken($users['unrelated'], AccountToken::PASSWORD_RESET, $plainToken, new \DateTimeImmutable($expires));
            if (null !== $used) {
                $token->markUsed(new \DateTimeImmutable($used));
            }
            $this->entityManager->persist($token);
            $manifest['reset_tokens'][$state] = ['token' => $plainToken, 'email' => $users['unrelated']->getEmail()];
        }

        $items = [];
        foreach (['tags' => [Tag::class, self::TAGS], 'categories' => [Category::class, self::CATEGORIES]] as $type => [$class, $definitions]) {
            foreach ($definitions as $persona => $personaItems) {
                foreach ($personaItems as $key => [$name, $color]) {
                    $item = new $class($users[$persona], $name, $color, self::id($runId, $type, $persona.'/'.$key));
                    $this->entityManager->persist($item);
                    $items[$type][$persona][$key] = $item;
                    $manifest[$type][$persona][$key] = ['id' => $item->getId(), 'name' => $name, 'color' => $color];
                }
            }
        }

        foreach (self::NOTES as $key => $shares) {
            [$tagKeys, $categoryKey] = self::NOTE_TAXONOMY[$key] ?? [[], null];
            $title = sprintf('[%s] %s note', $runId, str_replace('_', ' ', $key));
            $note = (new Note())->setOwner($users['owner']);
            self::forceId($note, self::id($runId, 'note', $key));

            // "shared_1" has a history: two earlier texts (by the owner, then the editor) before the current one.
            $drafts = 'shared_1' === $key
                ? [['owner', $title.' (first draft)', "First draft.\nLine that will be removed.\n<b>markup</b> stays text."], ['editor', $title, "Second draft: ünïcödé, 日本語, 💡.\nA line added by the editor."]]
                : [];
            foreach ($drafts as $index => [$persona, $draftTitle, $draftContent]) {
                $note->setTitle($draftTitle)->setContent($draftContent)->setVersion($index + 1);
                $this->entityManager->persist(new NoteRevision($note, $index + 1, 0 === $index ? NoteRevision::CREATED : NoteRevision::UPDATED, $users[$persona]->getId()));
            }
            $note->setTitle($title)->setContent(sprintf('Fixture note "%s" for run "%s".', $key, $runId))->setVersion(count($drafts) + 1);
            $this->entityManager->persist(new NoteRevision($note, $note->getVersion(), [] === $drafts ? NoteRevision::CREATED : NoteRevision::UPDATED, $users['owner']->getId()));
            $this->entityManager->persist($note);
            $note->replaceTags(array_map(static fn (string $tagKey): Tag => $items['tags']['owner'][$tagKey], $tagKeys));
            $note->setCategory(null === $categoryKey ? null : $items['categories']['owner'][$categoryKey]);
            $manifest['notes'][$key] = ['id' => $note->getId(), 'title' => $note->getTitle(), 'version' => $note->getVersion(), 'shares' => [], 'tags' => $tagKeys, 'category' => $categoryKey];

            foreach ($shares as $persona => $role) {
                $share = new NoteShare($note, $users[$persona], $role, self::id($runId, 'share', $key.'/'.$persona));
                $this->entityManager->persist($share);
                $manifest['notes'][$key]['shares'][$persona] = ['id' => $share->getId(), 'role' => $role->value];
            }

            $manifest['notes'][$key]['attachments'] = [];
            foreach ('shared_1' === $key ? self::ATTACHMENTS : [] as $attachmentKey => [$filename, $mimeType, $base64]) {
                $content = (string) base64_decode($base64, true);
                $attachment = new NoteAttachment(
                    $note,
                    $users['owner']->getId(),
                    $filename,
                    $this->storage->storeContent($content),
                    $mimeType,
                    strlen($content),
                    hash('sha256', $content),
                    self::id($runId, 'attachment', $key.'/'.$attachmentKey),
                );
                $this->entityManager->persist($attachment);
                $manifest['notes'][$key]['attachments'][$attachmentKey] = [
                    'id' => $attachment->getId(),
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'size' => $attachment->getSize(),
                    'sha256' => $attachment->getSha256(),
                ];
            }
        }

        $timestamps = [];
        foreach ($this->searchNotes($runId) as $key => $definition) {
            $note = (new Note())->setOwner($users[$definition['owner']])->setTitle($definition['title'])->setContent($definition['content']);
            self::forceId($note, $definition['id']);
            $note->replaceTags(array_map(static fn (string $tagKey): Tag => $items['tags']['owner'][$tagKey], $definition['tags']));
            $note->setCategory(null === $definition['category'] ? null : $items['categories']['owner'][$definition['category']]);
            $this->entityManager->persist($note);
            $this->entityManager->persist(new NoteRevision($note, 1, NoteRevision::CREATED, $users[$definition['owner']]->getId()));
            foreach ($definition['shares'] as $persona => $role) {
                $this->entityManager->persist(new NoteShare($note, $users[$persona], ShareRole::from($role), self::id($runId, 'share', $key.'/'.$persona)));
            }
            $timestamps[$definition['id']] = [$definition['created_at'], $definition['updated_at']];
            $manifest['search_notes'][$key] = $definition;
        }

        // Twenty tasks, five in each status: sixteen of the owner (some assigned to the editor or the viewer,
        // some linked to "shared_1", which all three can see) and four of the editor (two assigned to the owner).
        $positions = [];
        $sharedNote = $this->entityManager->getReference(Note::class, $manifest['notes']['shared_1']['id']);
        for ($i = 1; $i <= self::TASKS; ++$i) {
            $key = sprintf('task_%02d', $i);
            $ownerPersona = 0 === $i % 5 ? 'editor' : 'owner';
            $assigneePersona = match (true) {
                'editor' === $ownerPersona => 0 === $i % 10 ? 'owner' : null,
                0 === $i % 3 => 'editor',
                0 === $i % 7 => 'viewer',
                default => null,
            };
            $status = TaskStatus::cases()[$i % 4];
            $linked = 1 === $i % 4;
            $dueAt = 0 === $i % 6 ? null : (new \DateTimeImmutable('2026-11-01T09:00:00+00:00'))->modify(sprintf('+%d days', $i));

            $task = (new Task($users[$ownerPersona], sprintf('[%s] Task %02d', $runId, $i), self::id($runId, 'task', $key)))
                ->setDescription(sprintf('Fixture task %02d.', $i))
                ->setStatus($status)
                ->setPriority(TaskPriority::cases()[$i % 3])
                ->setDueAt($dueAt)
                ->setAssignee(null === $assigneePersona ? null : $users[$assigneePersona])
                ->setNote($linked ? $sharedNote : null)
                ->setPosition($positions[$ownerPersona][$status->value] ?? 0);
            $positions[$ownerPersona][$status->value] = $task->getPosition() + 1;
            $this->entityManager->persist($task);

            $manifest['tasks'][$key] = [
                'id' => $task->getId(),
                'title' => $task->getTitle(),
                'status' => $status->value,
                'priority' => $task->getPriority()->value,
                'position' => $task->getPosition(),
                'owner' => $ownerPersona,
                'assignee' => $assigneePersona,
                'note' => $linked ? 'shared_1' : null,
                'due_at' => $dueAt?->format(\DATE_ATOM),
            ];
        }

        // Plain notes of the owner, a few more than one bulk request accepts. All share one timestamp.
        for ($i = 1; $i <= self::BULK_NOTES; ++$i) {
            $key = sprintf('bulk_%03d', $i);
            $note = (new Note())->setOwner($users['owner'])->setTitle(sprintf('[%s] bulk %03d', $runId, $i))->setContent('Bulk fixture note.');
            self::forceId($note, self::id($runId, 'note', $key));
            $this->entityManager->persist($note);
            $this->entityManager->persist(new NoteRevision($note, 1, NoteRevision::CREATED, $users['owner']->getId()));
            $timestamps[$note->getId()] = ['2026-01-10T00:00:00+00:00', '2026-01-10T00:00:00+00:00'];
            $manifest['bulk_notes'][$key] = $note->getId();
        }

        $this->entityManager->flush();

        // Fixed timestamps (several notes share each one), written past the entity's own clock.
        foreach ($timestamps as $id => [$createdAt, $updatedAt]) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE notes SET created_at = ?, updated_at = ? WHERE id = ?',
                [substr(str_replace('T', ' ', $createdAt), 0, 19), substr(str_replace('T', ' ', $updatedAt), 0, 19), $id],
            );
        }
        $this->entityManager->clear();

        return $manifest;
    }

    /**
     * 60 notes for search, sorting and paging: 40 owned by the owner (some shared with the viewer
     * and the editor) and 20 by the editor (half of them shared with the owner). Six notes share
     * each "updated_at".
     *
     * @return array<string, array<string, mixed>>
     */
    private function searchNotes(string $runId): array
    {
        $notes = [];
        for ($i = 1; $i <= self::SEARCH_NOTES; ++$i) {
            $key = sprintf('search_%02d', $i);
            $ownedByOwner = 0 !== $i % 3;

            $shares = [];
            if ($ownedByOwner && 0 === $i % 5) {
                $shares['viewer'] = ShareRole::Viewer->value;
            }
            if ($ownedByOwner && 0 === $i % 10) {
                $shares['editor'] = ShareRole::Editor->value;
            }
            if (!$ownedByOwner && 0 === $i % 6) {
                $shares['owner'] = ShareRole::Viewer->value;
            }

            // Tags and categories always belong to the note's owner; only the owner persona has fixture taxonomy.
            $tags = $ownedByOwner ? array_values(array_unique([self::SEARCH_TAGS[$i % 5], ...(0 === $i % 4 ? ['work'] : [])])) : [];

            $notes[$key] = [
                'id' => self::id($runId, 'note', $key),
                'title' => sprintf('[%s] %s %02d', $runId, self::SEARCH_WORDS[$i % 10], $i),
                'content' => sprintf('Search fixture %02d: %s', $i, self::SEARCH_KEYWORDS[$i % 3]),
                'owner' => $ownedByOwner ? 'owner' : 'editor',
                'created_at' => (new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))->modify(sprintf('+%d hours', $i))->format(\DATE_ATOM),
                'updated_at' => (new \DateTimeImmutable('2026-02-01T12:00:00+00:00'))->modify(sprintf('+%d days', intdiv($i - 1, 6)))->format(\DATE_ATOM),
                'tags' => $tags,
                'category' => $ownedByOwner && 0 === $i % 7 ? 'projects' : null,
                'shares' => $shares,
            ];
        }

        return $notes;
    }

    /**
     * Removes the run's personas with everything they own or were granted, and the related audit records.
     *
     * @return int number of personas removed
     */
    public function purge(string $runId): int
    {
        self::assertValidRunId($runId);

        // Personas are found by their reserved email address or their fixture ID:
        // a test may have changed either one (email change), but not both.
        $emails = array_map(static fn (string $persona): string => self::email($runId, $persona), self::PERSONAS);
        $fixtureIds = array_map(static fn (string $persona): string => self::id($runId, 'user', $persona), self::PERSONAS);
        $userIds = array_column(
            $this->entityManager->createQueryBuilder()
                ->select('u.id')->from(User::class, 'u')
                ->where('u.email IN (:emails) OR u.id IN (:ids)')
                ->setParameter('emails', $emails)
                ->setParameter('ids', $fixtureIds)
                ->getQuery()->getScalarResult(),
            'id',
        );
        $this->throttle->forget([...$emails, ...$userIds]);
        if ([] === $userIds) {
            return 0;
        }

        // The attachment rows go with the notes; their files have to be removed here.
        $files = $this->attachments->storageKeysOfOwners($userIds);

        $this->entityManager->createQueryBuilder()
            ->delete(AuditEvent::class, 'a')
            ->where('a.actorId IN (:users) OR a.targetUserId IN (:users)')
            ->setParameter('users', $userIds)
            ->getQuery()
            ->execute();

        // Notes, shares, tags, categories, tokens and sign-up codes go with the users through ON DELETE CASCADE.
        $removed = $this->entityManager->createQueryBuilder()
            ->delete(User::class, 'u')
            ->where('u.id IN (:users)')
            ->setParameter('users', $userIds)
            ->getQuery()
            ->execute();

        $this->storage->delete($files);
        $this->entityManager->clear();

        return (int) $removed;
    }

    public static function assertValidRunId(string $runId): void
    {
        if (1 !== preg_match(self::RUN_ID_PATTERN, $runId)) {
            throw new \InvalidArgumentException('Run ID must be 1-32 characters: lowercase letters, digits and dashes, not starting with a dash.');
        }
    }

    public static function email(string $runId, string $persona): string
    {
        return sprintf('%s.%s@qa-fixtures.test', $persona, $runId);
    }

    private static function id(string $runId, string $type, string $key): string
    {
        // Not tied to VERSION: IDs of a run stay the same when the fixture set grows.
        return Uuid::fromName(sprintf('sharing-fixtures/v1/%s/%s/%s', $runId, $type, $key));
    }

    private static function forceId(object $entity, string $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
