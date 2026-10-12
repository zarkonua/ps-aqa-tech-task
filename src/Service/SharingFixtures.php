<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Category;
use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deterministic personas, notes, shares, tags and categories for exercising sharing and taxonomy.
 * Everything is keyed by a run ID: the same run ID always yields the same emails and IDs,
 * and can be purged without touching other runs or real data.
 */
final class SharingFixtures
{
    public const VERSION = 2;
    public const DEFAULT_PASSWORD = 'Fixture-Passw0rd!';
    public const PERSONAS = ['owner', 'viewer', 'editor', 'unrelated'];

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
    private const NOTE_TAXONOMY = [
        'private_2' => [['work', 'uber', 'japanese', 'cafe', 'idea', 'urgent', 'later', 'home', 'reading', 'travel'], 'projects'],
        'shared_1' => [['work', 'uber'], 'personal'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
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

        $manifest = ['version' => self::VERSION, 'run_id' => $runId, 'password' => $password, 'users' => [], 'tags' => [], 'categories' => [], 'notes' => []];

        $users = [];
        foreach (self::PERSONAS as $persona) {
            $user = (new User())->setEmail(self::email($runId, $persona))->setIsVerified(true);
            self::forceId($user, self::id($runId, 'user', $persona));
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $this->entityManager->persist($user);
            $users[$persona] = $user;
            $manifest['users'][$persona] = ['id' => $user->getId(), 'email' => $user->getEmail()];
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
            $note = (new Note())
                ->setOwner($users['owner'])
                ->setTitle(sprintf('[%s] %s note', $runId, str_replace('_', ' ', $key)))
                ->setContent(sprintf('Fixture note "%s" for run "%s".', $key, $runId));
            self::forceId($note, self::id($runId, 'note', $key));
            $this->entityManager->persist($note);
            $note->replaceTags(array_map(static fn (string $tagKey): Tag => $items['tags']['owner'][$tagKey], $tagKeys));
            $note->setCategory(null === $categoryKey ? null : $items['categories']['owner'][$categoryKey]);
            $manifest['notes'][$key] = ['id' => $note->getId(), 'title' => $note->getTitle(), 'shares' => [], 'tags' => $tagKeys, 'category' => $categoryKey];

            foreach ($shares as $persona => $role) {
                $share = new NoteShare($note, $users[$persona], $role, self::id($runId, 'share', $key.'/'.$persona));
                $this->entityManager->persist($share);
                $manifest['notes'][$key]['shares'][$persona] = ['id' => $share->getId(), 'role' => $role->value];
            }
        }

        $this->entityManager->flush();

        return $manifest;
    }

    /**
     * Removes the run's personas with everything they own or were granted, and the related audit records.
     *
     * @return int number of personas removed
     */
    public function purge(string $runId): int
    {
        self::assertValidRunId($runId);

        // Personas are found by their reserved email addresses, whatever their IDs are.
        $emails = array_map(static fn (string $persona): string => self::email($runId, $persona), self::PERSONAS);
        $userIds = array_column(
            $this->entityManager->createQueryBuilder()
                ->select('u.id')->from(User::class, 'u')
                ->where('u.email IN (:emails)')->setParameter('emails', $emails)
                ->getQuery()->getScalarResult(),
            'id',
        );
        if ([] === $userIds) {
            return 0;
        }

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
