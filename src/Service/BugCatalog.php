<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The defects that can be switched on for a test run. This is ground truth for whoever scores the
 * tests: it is served only by the test-admin API and must not reach the agents under test.
 */
final class BugCatalog
{
    public const PAGINATION = 'BUG-001';
    public const AUTH_BYPASS = 'BUG-002';
    public const TAG_MATCH = 'BUG-003';
    public const STALE_READ = 'BUG-004';
    public const SORTING = 'BUG-005';
    public const STATUS_CODE = 'BUG-006';
    public const DUPLICATE_NOTIFICATION = 'BUG-007';
    public const VALIDATION = 'BUG-008';
    public const TOTAL_COUNT = 'BUG-009';
    public const TRANSITION = 'BUG-010';

    /**
     * "challenge_id" is the name a runner can show or log without saying what the defect is.
     */
    private const BUGS = [
        self::PAGINATION => [
            'challenge_id' => 'ch-4f7a1c', 'name' => 'Pagination off by one', 'severity' => 'major', 'feature' => 'search', 'surface' => 'api',
            'preconditions' => 'A user with more notes than one page holds.',
            'expected' => 'Page N of GET /api/notes starts right after the last note of page N-1.',
            'actual' => 'Every page after the first starts one note too late: one note is never listed.',
            'remediation' => 'Offset of page N is (N-1) × limit.',
            'conflicts' => [self::SORTING, self::TOTAL_COUNT],
        ],
        self::AUTH_BYPASS => [
            'challenge_id' => 'ch-9b2e60', 'name' => 'Viewer can write', 'severity' => 'critical', 'feature' => 'sharing', 'surface' => 'auth',
            'preconditions' => 'A note shared with a user as viewer.',
            'expected' => 'A viewer who edits the note (PUT or PATCH, its tags, category or files) gets 403.',
            'actual' => 'The viewer is treated as an editor and the change is saved.',
            'remediation' => 'Only owner and editor may edit.',
            'conflicts' => [],
        ],
        self::TAG_MATCH => [
            'challenge_id' => 'ch-c31d08', 'name' => 'Tag ANY and ALL swapped', 'severity' => 'major', 'feature' => 'tags', 'surface' => 'filter',
            'preconditions' => 'Notes with different tags; a query with two or more tagIds.',
            'expected' => 'tagMatch=any (the default) lists notes with at least one of the tags, tagMatch=all those with every tag.',
            'actual' => 'The two are exchanged.',
            'remediation' => 'any → OR, all → AND.',
            'conflicts' => [],
        ],
        self::STALE_READ => [
            'challenge_id' => 'ch-7e5512', 'name' => 'Stale read after update', 'severity' => 'major', 'feature' => 'notes', 'surface' => 'state',
            'preconditions' => 'A note that has been edited at least once.',
            'expected' => 'GET /api/notes/{id} returns the title and content that were saved last.',
            'actual' => 'It returns the title and content of the revision before, next to the current version number.',
            'remediation' => 'Serve the stored note, not a cached earlier copy.',
            'conflicts' => [],
        ],
        self::SORTING => [
            'challenge_id' => 'ch-a06f39', 'name' => 'Title sort inverted', 'severity' => 'minor', 'feature' => 'search', 'surface' => 'sort',
            'preconditions' => 'Several notes; GET /api/notes?sortBy=title.',
            'expected' => 'order=asc (the default for title) lists A to Z, order=desc Z to A.',
            'actual' => 'The direction is reversed for sortBy=title. The older sort[title] parameter is not affected.',
            'remediation' => 'Apply the requested direction.',
            'conflicts' => [self::PAGINATION],
        ],
        self::STATUS_CODE => [
            'challenge_id' => 'ch-2d8b47', 'name' => 'Wrong status on create', 'severity' => 'minor', 'feature' => 'tasks', 'surface' => 'api',
            'preconditions' => 'None.',
            'expected' => 'POST /api/tasks answers 201 Created.',
            'actual' => 'It answers 200 OK (the task is created).',
            'remediation' => 'Return 201 for a created resource.',
            'conflicts' => [],
        ],
        self::DUPLICATE_NOTIFICATION => [
            'challenge_id' => 'ch-e1930a', 'name' => 'Duplicate notification', 'severity' => 'minor', 'feature' => 'notifications', 'surface' => 'async',
            'preconditions' => 'A note is shared with another user.',
            'expected' => 'The user gets one "share_granted" notification (and at most one email) per share.',
            'actual' => 'They get two.',
            'remediation' => 'Deliver each event once.',
            'conflicts' => [],
        ],
        self::VALIDATION => [
            'challenge_id' => 'ch-58c7d2', 'name' => 'Description limit not enforced', 'severity' => 'major', 'feature' => 'tasks', 'surface' => 'api',
            'preconditions' => 'None.',
            'expected' => 'A task description longer than 5000 characters is refused with 422.',
            'actual' => 'It is accepted and stored.',
            'remediation' => 'Enforce the 5000-character limit.',
            'conflicts' => [],
        ],
        self::TOTAL_COUNT => [
            'challenge_id' => 'ch-b47e9f', 'name' => 'Total count one too high', 'severity' => 'minor', 'feature' => 'search', 'surface' => 'ui',
            'preconditions' => 'Any notes list.',
            'expected' => 'X-Total-Count, and the count and page number shown above the list, equal the number of matching notes.',
            'actual' => 'The total is one more than there are notes; the list can show a last page that is empty.',
            'remediation' => 'Report the real number of matches.',
            'conflicts' => [self::PAGINATION],
        ],
        self::TRANSITION => [
            'challenge_id' => 'ch-06a2e5', 'name' => 'Any status move allowed', 'severity' => 'major', 'feature' => 'tasks', 'surface' => 'state',
            'preconditions' => 'A task in backlog.',
            'expected' => 'Moving a task from backlog straight to done is refused with 409.',
            'actual' => 'Any status can be set from any other, including the one the task already has.',
            'remediation' => 'Allow only moves to a neighbouring status.',
            'conflicts' => [],
        ],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(static fn (string $id, array $bug): array => ['bug_id' => $id] + $bug, array_keys(self::BUGS), self::BUGS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $bugId): ?array
    {
        return isset(self::BUGS[$bugId]) ? ['bug_id' => $bugId] + self::BUGS[$bugId] : null;
    }

    /**
     * Accepts a bug ID or its challenge ID.
     */
    public static function resolve(string $idOrChallenge): ?string
    {
        if (isset(self::BUGS[$idOrChallenge])) {
            return $idOrChallenge;
        }
        foreach (self::BUGS as $id => $bug) {
            if ($bug['challenge_id'] === $idOrChallenge) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Pairs that cannot be on together: one would make the other impossible to tell apart.
     *
     * @param list<string> $bugIds
     *
     * @return list<array{string, string}>
     */
    public static function conflictsAmong(array $bugIds): array
    {
        $conflicts = [];
        foreach ($bugIds as $index => $a) {
            foreach (array_slice($bugIds, $index + 1) as $b) {
                if (in_array($b, self::BUGS[$a]['conflicts'], true) || in_array($a, self::BUGS[$b]['conflicts'], true)) {
                    $conflicts[] = [$a, $b];
                }
            }
        }

        return $conflicts;
    }
}
