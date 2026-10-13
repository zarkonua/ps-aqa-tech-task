<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TestRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, resets and removes test runs. Each run has its own personas and data (see SharingFixtures),
 * so runs cannot see or disturb each other.
 */
final class RunManager
{
    public const MAX_RUNS = 50;
    public const MAX_CLOCK_OFFSET = 10 * 365 * 86400;

    /** Named fixture sets: which optional parts each one has on top of the personas and base notes. */
    public const FIXTURES = [
        'full' => SharingFixtures::PARTS,
        'basic-notes' => [],
        'sharing-rbac' => ['history', 'notifications', 'audit'],
        'search-edge' => ['search'],
        'bulk-mixed' => ['search', 'bulk'],
        'attachments' => ['attachments'],
        'concurrency' => ['history', 'tasks'],
        'async-faults' => ['tasks', 'notifications'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharingFixtures $fixtures,
        private readonly MailhogCleaner $mailhog,
        private readonly AuditLogger $audit,
        private readonly RunClock $clock,
    ) {
    }

    public function find(string $runId): ?TestRun
    {
        return $this->entityManager->find(TestRun::class, $runId);
    }

    /**
     * @return list<TestRun>
     */
    public function all(): array
    {
        return $this->entityManager->getRepository(TestRun::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return array{run: TestRun, token: string} the run and the token that operates on it (shown only now)
     *
     * @throws RunConflict when the run exists already, the quota is used up, or the run is being changed
     */
    public function create(string $fixture, int $seed, ?string $runId = null): array
    {
        $runId ??= 'r'.bin2hex(random_bytes(6));
        SharingFixtures::assertValidRunId($runId);

        return $this->locked($runId, function () use ($runId, $fixture, $seed): array {
            if (null !== $this->find($runId)) {
                throw new RunConflict(sprintf('Run "%s" already exists. Reset it, or choose another ID.', $runId));
            }
            if ($this->entityManager->getRepository(TestRun::class)->count([]) >= self::MAX_RUNS) {
                throw new RunConflict(sprintf('The limit of %d runs is reached. Delete runs that are no longer needed.', self::MAX_RUNS), true);
            }

            $token = bin2hex(random_bytes(24));
            $run = new TestRun($runId, $fixture, SharingFixtures::VERSION, $seed, hash('sha256', $token));
            $manifest = $this->seedData($runId, $fixture, $seed, 0);
            $run->setManifest($manifest, self::semanticHash($manifest, $runId), SharingFixtures::VERSION);
            $this->entityManager->persist($run);
            $this->audit->record('run.created', null, null, metadata: ['fixture' => $fixture, 'seed' => $seed], subjectId: $runId);
            $this->entityManager->flush();

            return ['run' => $run, 'token' => $token];
        });
    }

    /**
     * Brings the run back to the state it was created in: data, files, mail, sign-ins and clock.
     * Doing it twice gives the same result as doing it once.
     *
     * @return array{run: TestRun, mail_deleted: int|null} the run as it is now, and the captured mails removed
     *                                                     from MailHog (null when MailHog could not be asked)
     */
    public function reset(TestRun $run): array
    {
        $runId = $run->getId();

        return $this->locked($runId, function () use ($runId): array {
            $this->entityManager->clear();
            $run = $this->find($runId) ?? throw new RunConflict(sprintf('Run "%s" no longer exists.', $runId));
            $resets = $run->getResets() + 1;

            // Seeding starts by purging and leaves nothing loaded, so the run is fetched again afterwards.
            $manifest = $this->seedData($runId, $run->getFixture(), $run->getSeed(), $resets);
            $run = $this->find($runId) ?? throw new RunConflict(sprintf('Run "%s" no longer exists.', $runId));
            // A reset is also the way back to a run without injected defects.
            $run->countReset()->setClockOffsetSeconds(0)->setActiveBugs([])->setManifest($manifest, self::semanticHash($manifest, $runId), SharingFixtures::VERSION);
            $this->audit->record('run.reset', null, null, metadata: ['resets' => $resets], subjectId: $runId);
            $this->entityManager->flush();
            $this->clock->forget();

            return ['run' => $run, 'mail_deleted' => $this->mailhog->deleteMailTo($this->emails($run))];
        });
    }

    public function delete(TestRun $run): ?int
    {
        return $this->locked($run->getId(), function () use ($run): ?int {
            $emails = $this->emails($run);
            $this->fixtures->purge($run->getId());
            $run = $this->find($run->getId());
            if (null !== $run) {
                $this->audit->record('run.deleted', null, null, subjectId: $run->getId());
                $this->entityManager->remove($run);
                $this->entityManager->flush();
            }
            $this->clock->forget();

            return $this->mailhog->deleteMailTo($emails);
        });
    }

    /**
     * Switches on exactly these catalogued defects for the run (none, for an empty list).
     *
     * @param list<string> $bugIds IDs from BugCatalog, already checked for conflicts
     */
    public function setBugs(TestRun $run, array $bugIds): TestRun
    {
        return $this->locked($run->getId(), function () use ($run, $bugIds): TestRun {
            $this->entityManager->refresh($run);
            // Deliberately not written to the application's audit log, which the run's own administrator can read.
            $run->setActiveBugs($bugIds);
            $this->entityManager->flush();

            return $run;
        });
    }

    /**
     * @return TestRun the run with its new clock offset
     *
     * @throws RunConflict when the clock would end up more than ten years ahead
     */
    public function advanceClock(TestRun $run, int $seconds): TestRun
    {
        return $this->locked($run->getId(), function () use ($run, $seconds): TestRun {
            $this->entityManager->refresh($run);
            $offset = $run->getClockOffsetSeconds() + $seconds;
            if ($offset > self::MAX_CLOCK_OFFSET) {
                throw new RunConflict('The clock of a run cannot be more than ten years ahead. Reset the run to start over.');
            }
            $run->setClockOffsetSeconds($offset);
            $this->audit->record('run.clock_advanced', null, null, metadata: ['seconds' => $seconds, 'offset' => $offset], subjectId: $run->getId());
            $this->entityManager->flush();
            $this->clock->forget();

            return $run;
        });
    }

    /**
     * The manifest reduced to what the run contains, without what differs between runs of the same
     * content: the run ID, generated identifiers and the password.
     *
     * @param array<string, mixed> $manifest
     */
    public static function semanticHash(array $manifest, string $runId): string
    {
        unset($manifest['password'], $manifest['run_id']);
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $json = str_replace($runId, '{run}', $json);
        $seen = [];
        // UUIDs and 64-character tokens are replaced by the order they first appear in.
        $json = preg_replace_callback('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b[0-9a-f]{64}\b/', static function (array $m) use (&$seen): string {
            return '{id'.($seen[$m[0]] ??= count($seen) + 1).'}';
        }, $json);

        return hash('sha256', (string) $json);
    }

    /**
     * @return array<string, mixed> the manifest of the freshly seeded data
     */
    private function seedData(string $runId, string $fixture, int $seed, int $resets): array
    {
        // Sign-ins from before a reset must not work on the personas created anew under the same IDs:
        // each round starts their token versions well above anything the previous one could have reached.
        return ['fixture' => $fixture] + $this->fixtures->seed($runId, SharingFixtures::DEFAULT_PASSWORD, $seed, self::FIXTURES[$fixture], $resets * 10_000);
    }

    /**
     * @return list<string>
     */
    private function emails(TestRun $run): array
    {
        return array_map(static fn (string $persona): string => SharingFixtures::email($run->getId(), $persona), SharingFixtures::PERSONAS);
    }

    /**
     * Runs one change of a run at a time; a second one for the same run waits briefly, then gives up.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function locked(string $runId, \Closure $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $lock = 'test-run:'.$runId;
        if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lock])) {
            throw new RunConflict(sprintf('Run "%s" is being changed by another request. Try again in a moment.', $runId));
        }

        try {
            return $work();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
