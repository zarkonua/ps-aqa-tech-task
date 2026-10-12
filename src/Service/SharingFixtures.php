<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deterministic personas and notes for exercising notes sharing.
 * Everything is keyed by a run ID: the same run ID always yields the same emails and IDs,
 * and can be purged without touching other runs or real data.
 */
final class SharingFixtures
{
    public const VERSION = 1;
    public const DEFAULT_PASSWORD = 'Fixture-Passw0rd!';
    public const PERSONAS = ['owner', 'viewer', 'editor', 'unrelated'];

    private const RUN_ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    private const NOTES = [
        'private_1' => [],
        'private_2' => [],
        'shared_1' => ['viewer' => ShareRole::Viewer, 'editor' => ShareRole::Editor],
        'shared_2' => ['viewer' => ShareRole::Viewer],
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

        $manifest = ['version' => self::VERSION, 'run_id' => $runId, 'password' => $password, 'users' => [], 'notes' => []];

        $users = [];
        foreach (self::PERSONAS as $persona) {
            $user = (new User())->setEmail(self::email($runId, $persona))->setIsVerified(true);
            self::forceId($user, self::id($runId, 'user', $persona));
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $this->entityManager->persist($user);
            $users[$persona] = $user;
            $manifest['users'][$persona] = ['id' => $user->getId(), 'email' => $user->getEmail()];
        }

        foreach (self::NOTES as $key => $shares) {
            $note = (new Note())
                ->setOwner($users['owner'])
                ->setTitle(sprintf('[%s] %s note', $runId, str_replace('_', ' ', $key)))
                ->setContent(sprintf('Fixture note "%s" for run "%s".', $key, $runId));
            self::forceId($note, self::id($runId, 'note', $key));
            $this->entityManager->persist($note);
            $manifest['notes'][$key] = ['id' => $note->getId(), 'title' => $note->getTitle(), 'shares' => []];

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

        $userIds = array_map(static fn (string $persona): string => self::id($runId, 'user', $persona), self::PERSONAS);
        $emails = array_map(static fn (string $persona): string => self::email($runId, $persona), self::PERSONAS);
        $noteIds = array_map(static fn (string $key): string => self::id($runId, 'note', $key), array_keys(self::NOTES));

        $this->entityManager->createQueryBuilder()
            ->delete(AuditEvent::class, 'a')
            ->where('a.actorId IN (:users) OR a.targetUserId IN (:users) OR a.noteId IN (:notes)')
            ->setParameter('users', $userIds)
            ->setParameter('notes', $noteIds)
            ->getQuery()
            ->execute();

        // Notes, shares, tokens and sign-up codes go with the users through ON DELETE CASCADE.
        $removed = $this->entityManager->createQueryBuilder()
            ->delete(User::class, 'u')
            ->where('u.id IN (:users) AND u.email IN (:emails)')
            ->setParameter('users', $userIds)
            ->setParameter('emails', $emails)
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
        return Uuid::fromName(sprintf('sharing-fixtures/v%d/%s/%s/%s', self::VERSION, $runId, $type, $key));
    }

    private static function forceId(object $entity, string $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
