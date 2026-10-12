<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\Task;
use PHPUnit\Framework\Attributes\DataProvider;

final class TaskTest extends ApiTestCase
{
    private const MISSING = '00000000-0000-4000-8000-000000000000';

    protected function tearDown(): void
    {
        // Reorder events have neither a subject nor a target the base class cleans up by.
        $this->em()->createQueryBuilder()->delete(AuditEvent::class, 'a')->where('a.eventType LIKE :type')->setParameter('type', 'task.%')->getQuery()->execute();

        parent::tearDown();
    }

    public function testCreateWithDefaults(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => "  Write   the\treport "], ['X-Correlation-ID' => 'task-test-0001']));
        $task = $this->json();

        self::assertSame(
            ['id', 'title', 'description', 'status', 'priority', 'due_at', 'position', 'version', 'role', 'owner', 'assignee', 'note', 'allowed_statuses', 'created_at', 'updated_at'],
            array_keys($task),
        );
        self::assertSame('/api/tasks/'.$task['id'], $this->client->getResponse()->headers->get('Location'));
        self::assertSame(
            ['Write the report', '', 'backlog', 'medium', null, 0, 1, 'owner', null, null, ['todo']],
            [$task['title'], $task['description'], $task['status'], $task['priority'], $task['due_at'], $task['position'], $task['version'], $task['role'], $task['assignee'], $task['note'], $task['allowed_statuses']],
        );
        self::assertSame(['id' => $this->userIds['owner'], 'email' => $this->email('owner')], $task['owner']);

        // New tasks go to the end of the backlog.
        self::assertSame(1, $this->createTask('owner', ['title' => 'Second'])['position']);
        self::assertSame(0, $this->createTask('editor', ['title' => 'Somebody else'])['position']);

        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks/'.$task['id']));
        self::assertSame($task, $this->json());

        $event = $this->em()->getRepository(AuditEvent::class)->findOneBy(['subjectId' => $task['id']]);
        self::assertSame([AuditEvent::TASK_CREATED, $this->userIds['owner'], 'task-test-0001'], [$event->getEventType(), $event->getActorId(), $event->getCorrelationId()]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int, string|null}>
     */
    public static function invalidTasks(): iterable
    {
        yield 'malformed JSON' => ['{"title": "x"', 400, null];
        yield 'JSON list' => ['["x"]', 400, null];
        yield 'empty body' => ['', 422, 'title'];
        yield 'null title' => [['title' => null], 422, 'title'];
        yield 'empty title' => [['title' => ''], 422, 'title'];
        yield 'blank title' => [['title' => " \t "], 422, 'title'];
        yield 'title as number' => [['title' => 5], 422, 'title'];
        yield 'title 151 characters' => [['title' => str_repeat('a', 151)], 422, 'title'];
        yield 'description 5001 characters' => [['title' => 'x', 'description' => str_repeat('é', 5001)], 422, 'description'];
        yield 'description as list' => [['title' => 'x', 'description' => ['a']], 422, 'description'];
        yield 'unknown priority' => [['title' => 'x', 'priority' => 'urgent'], 422, 'priority'];
        yield 'priority wrong case' => [['title' => 'x', 'priority' => 'High'], 422, 'priority'];
        yield 'null priority' => [['title' => 'x', 'priority' => null], 422, 'priority'];
        yield 'due in words' => [['title' => 'x', 'due_at' => 'tomorrow'], 422, 'due_at'];
        yield 'due date only' => [['title' => 'x', 'due_at' => '2026-10-11'], 422, 'due_at'];
        yield 'due without zone' => [['title' => 'x', 'due_at' => '2026-10-11T08:30:00'], 422, 'due_at'];
        yield 'due impossible date' => [['title' => 'x', 'due_at' => '2026-02-30T08:30:00Z'], 422, 'due_at'];
        yield 'due impossible time' => [['title' => 'x', 'due_at' => '2026-10-11T24:30:00Z'], 422, 'due_at'];
        yield 'due as timestamp' => [['title' => 'x', 'due_at' => 1791800000], 422, 'due_at'];
        yield 'assignee not an ID' => [['title' => 'x', 'assignee_id' => 'me'], 422, 'assignee_id'];
        yield 'note not an ID' => [['title' => 'x', 'note_id' => 123], 422, 'note_id'];
        yield 'status at creation' => [['title' => 'x', 'status' => 'done'], 422, 'status'];
        yield 'position at creation' => [['title' => 'x', 'position' => 3], 422, 'position'];
        yield 'owner at creation' => [['title' => 'x', 'owner_id' => self::MISSING], 422, 'owner_id'];
    }

    #[DataProvider('invalidTasks')]
    public function testCreateValidation(array|string $body, int $expectedStatus, ?string $expectedField): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'POST', '/api/tasks', $body));
        if (null !== $expectedField) {
            self::assertContains($expectedField, array_column($this->json()['violations'], 'propertyPath'));
        }
        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks'));
        self::assertSame(0, $this->json()['total']);
    }

    public function testFieldBoundariesAndUnsafeText(): void
    {
        $task = $this->createTask('owner', [
            'title' => str_repeat('я', 150),
            'description' => str_repeat('日', 5000),
            'priority' => 'high',
        ]);
        self::assertSame([150, 5000, 'high'], [mb_strlen($task['title']), mb_strlen($task['description']), $task['priority']]);

        $markup = '<img src=x onerror=alert(1)> & "quotes"';
        $task = $this->createTask('owner', ['title' => $markup, 'description' => "'; DROP TABLE tasks; --"]);
        self::assertSame([$markup, "'; DROP TABLE tasks; --"], [$task['title'], $task['description']]);
        self::assertStringNotContainsString('<img', (string) $this->client->getResponse()->getContent());
    }

    public function testDueTimeIsStoredAndReturnedInUtc(): void
    {
        $task = $this->createTask('owner', ['title' => 'Kyiv afternoon', 'due_at' => '2026-10-20T15:00:00+03:00']);
        self::assertSame('2026-10-20T12:00:00+00:00', $task['due_at']);

        foreach (['2026-12-31T23:30:00-05:00' => '2027-01-01T04:30:00+00:00', '2026-03-29T01:30:00Z' => '2026-03-29T01:30:00+00:00', '2026-06-01T08:00:00.123456+09:30' => '2026-05-31T22:30:00+00:00'] as $sent => $stored) {
            self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['due_at' => $sent]), $sent);
            self::assertSame($stored, $this->json()['due_at']);
        }
        self::assertSame($stored, $this->em()->find(Task::class, $task['id'])->getDueAt()->format(\DATE_ATOM));

        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['due_at' => null]));
        self::assertNull($this->json()['due_at']);
        self::assertSame(422, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['due_at' => '20.10.2026 15:00']));
    }

    public function testAssigneeMustBeACollaboratorAndSeesTheTask(): void
    {
        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks/assignees'));
        self::assertSame(
            [$this->email('owner'), $this->email('editor'), $this->email('viewer')],
            array_column($this->json()['items'], 'email'),
            'me first, then the people I share notes with',
        );
        self::assertSame(200, $this->api('editor', 'GET', '/api/tasks/assignees'));
        self::assertSame([$this->email('editor'), $this->email('owner')], array_column($this->json()['items'], 'email'));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/tasks/assignees'));
        self::assertSame([$this->email('unrelated')], array_column($this->json()['items'], 'email'));

        // Somebody I do not work with, or nobody at all: the same answer.
        foreach ([$this->userIds['unrelated'], self::MISSING] as $stranger) {
            self::assertSame(404, $this->api('owner', 'POST', '/api/tasks', ['title' => 'x', 'assignee_id' => $stranger]));
            self::assertSame(['error' => 'Assignee not found.'], $this->json());
        }

        $task = $this->createTask('owner', ['title' => 'For the editor', 'assignee_id' => strtoupper($this->userIds['editor'])]);
        self::assertSame(['id' => $this->userIds['editor'], 'email' => $this->email('editor')], $task['assignee']);

        // The assignee finds it on their board; nobody else does.
        self::assertSame(200, $this->api('editor', 'GET', '/api/tasks'));
        self::assertSame([[$task['id'], 'assignee']], array_map(static fn (array $t): array => [$t['id'], $t['role']], $this->json()['items']));
        self::assertSame(200, $this->api('editor', 'GET', '/api/tasks/'.$task['id']));
        foreach (['viewer', 'unrelated'] as $persona) {
            self::assertSame(200, $this->api($persona, 'GET', '/api/tasks'));
            self::assertSame(0, $this->json()['total'], $persona);
            self::assertSame(404, $this->api($persona, 'GET', '/api/tasks/'.$task['id']), $persona);
        }

        // Unassigning takes it off their board at once.
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['assignee_id' => null]));
        self::assertSame(404, $this->api('editor', 'GET', '/api/tasks/'.$task['id']));
    }

    public function testNoteLink(): void
    {
        $task = $this->createTask('owner', ['title' => 'About a note', 'note_id' => $this->noteIds['shared_1'], 'assignee_id' => $this->userIds['viewer']]);
        self::assertSame(['id' => $this->noteIds['shared_1'], 'title' => $this->runId.' shared_1'], $task['note']);

        // A note the caller cannot see, or that does not exist.
        foreach ([[$this->noteIds['private_1'], 'editor'], [self::MISSING, 'owner']] as [$noteId, $persona]) {
            self::assertSame(404, $this->api($persona, 'POST', '/api/tasks', ['title' => 'x', 'note_id' => $noteId]));
            self::assertSame(['error' => 'Note not found.'], $this->json());
        }

        // The assignee must be able to open the linked note.
        self::assertSame(422, $this->api('owner', 'POST', '/api/tasks', ['title' => 'x', 'note_id' => $this->noteIds['private_1'], 'assignee_id' => $this->userIds['editor']]));
        self::assertSame('assignee_id', $this->json()['violations'][0]['propertyPath']);
        self::assertSame(422, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['note_id' => $this->noteIds['private_1']]));
        self::assertSame(422, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['note_id' => $this->noteIds['shared_2'], 'assignee_id' => $this->userIds['editor']]));
        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks/'.$task['id']));
        self::assertSame([$this->noteIds['shared_1'], $this->userIds['viewer'], 1], [$this->json()['note']['id'], $this->json()['assignee']['id'], $this->json()['version']], 'refused changes left nothing behind');

        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks/assignees?noteId='.$this->noteIds['shared_2']));
        self::assertSame([$this->email('owner'), $this->email('viewer')], array_column($this->json()['items'], 'email'));
        self::assertSame(404, $this->api('editor', 'GET', '/api/tasks/assignees?noteId='.$this->noteIds['private_1']));
        self::assertSame(404, $this->api('owner', 'GET', '/api/tasks/assignees?noteId=nope'));

        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks?noteId='.$this->noteIds['shared_1']));
        self::assertSame([$task['id']], array_column($this->json()['items'], 'id'));

        // Once the share is revoked the assignee keeps the task but no longer sees the note behind it.
        self::assertSame(204, $this->api('owner', 'DELETE', $this->sharesUri('shared_1', 'shared_1/viewer')));
        self::assertSame(200, $this->api('viewer', 'GET', '/api/tasks/'.$task['id']));
        self::assertNull($this->json()['note']);

        // Deleting the note unlinks the task and keeps it.
        self::assertSame(204, $this->api('owner', 'DELETE', $this->noteUri('shared_1')));
        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks/'.$task['id']));
        self::assertSame([null, 'About a note'], [$this->json()['note'], $this->json()['title']]);
    }

    public function testTransitionsAreEnforcedByTheServer(): void
    {
        $task = $this->createTask('owner', ['title' => 'Move me', 'assignee_id' => $this->userIds['editor']]);
        $uri = '/api/tasks/'.$task['id'].'/transition';

        // Jumps and standing still are refused, and say what is possible.
        foreach (['in_progress', 'done', 'backlog'] as $target) {
            self::assertSame(409, $this->api('owner', 'POST', $uri, ['status' => $target]), $target);
            self::assertSame(['error' => "A task cannot move from backlog to $target.", 'current_status' => 'backlog', 'allowed' => ['todo']], $this->json());
        }

        $path = [['todo', ['backlog', 'in_progress']], ['in_progress', ['todo', 'done']], ['done', ['in_progress']], ['in_progress', ['todo', 'done']], ['todo', ['backlog', 'in_progress']], ['backlog', ['todo']]];
        foreach ($path as $step => [$target, $next]) {
            // Owner and assignee both may move it.
            self::assertSame(200, $this->api(0 === $step % 2 ? 'owner' : 'editor', 'POST', $uri, ['status' => $target]), $target);
            self::assertSame([$target, $next, $step + 2], [$this->json()['status'], $this->json()['allowed_statuses'], $this->json()['version']]);
        }

        self::assertSame(200, $this->api('owner', 'POST', $uri, ['status' => 'todo']));
        self::assertSame(409, $this->api('owner', 'POST', $uri, ['status' => 'done']));
        self::assertSame(['backlog', 'in_progress'], $this->json()['allowed']);

        foreach ([[], ['status' => null], ['status' => 'DONE'], ['status' => 'archived'], ['status' => 2], ['status' => 'todo', 'position' => 0], ['status' => 'in_progress', 'version' => '8']] as $body) {
            self::assertSame(422, $this->api('owner', 'POST', $uri, $body), json_encode($body));
        }
        self::assertSame(400, $this->api('owner', 'POST', $uri, '{'));
        self::assertSame(404, $this->api('viewer', 'POST', $uri, ['status' => 'in_progress']));
        self::assertSame(404, $this->api('owner', 'POST', '/api/tasks/'.self::MISSING.'/transition', ['status' => 'todo']));
        self::assertSame(401, $this->api(null, 'POST', $uri, ['status' => 'in_progress']));

        // A stale client: version 1 was seven moves ago.
        self::assertSame(409, $this->api('owner', 'POST', $uri, ['status' => 'in_progress', 'version' => 1]));
        self::assertSame(['error' => 'Task was changed by another request. Reload and try again.'], $this->json());
        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks/'.$task['id']));
        self::assertSame(['todo', 8], [$this->json()['status'], $this->json()['version']]);

        self::assertSame(7, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $task['id'], 'eventType' => AuditEvent::TASK_TRANSITIONED]));
    }

    public function testMovedTaskGoesToTheEndOfTheNewColumn(): void
    {
        $a = $this->createTask('owner', ['title' => 'A']);
        $b = $this->createTask('owner', ['title' => 'B']);
        $c = $this->createTask('owner', ['title' => 'C']);
        foreach ([$b, $a] as $task) {
            self::assertSame(200, $this->api('owner', 'POST', '/api/tasks/'.$task['id'].'/transition', ['status' => 'todo']));
        }

        self::assertSame([['C', 'backlog'], ['B', 'todo'], ['A', 'todo']], $this->board('owner'));
        self::assertSame(1, $this->createTask('owner', ['title' => 'D'])['position'] - $c['position'] + 0, 'the backlog continues after C');
    }

    public function testOnlyTheOwnerEditsAndDeletes(): void
    {
        $task = $this->createTask('owner', ['title' => 'Mine', 'assignee_id' => $this->userIds['editor']]);
        $uri = '/api/tasks/'.$task['id'];

        self::assertSame(200, $this->api('owner', 'PATCH', $uri, ['title' => 'Renamed', 'description' => 'd', 'priority' => 'low', 'version' => 1]));
        self::assertSame(['Renamed', 'd', 'low', 2, 'backlog'], [$this->json()['title'], $this->json()['description'], $this->json()['priority'], $this->json()['version'], $this->json()['status']]);

        // Nothing to change: same version, nothing audited.
        self::assertSame(200, $this->api('owner', 'PATCH', $uri, ['title' => ' Renamed ', 'priority' => 'low']));
        self::assertSame(2, $this->json()['version']);
        self::assertSame(200, $this->api('owner', 'PATCH', $uri, []));
        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $task['id'], 'eventType' => AuditEvent::TASK_UPDATED]));

        self::assertSame(409, $this->api('owner', 'PATCH', $uri, ['title' => 'Stale', 'version' => 1]));
        self::assertSame(422, $this->api('owner', 'PATCH', $uri, ['status' => 'done']));
        self::assertSame([['propertyPath' => 'status', 'message' => 'Change the status with POST /api/tasks/{id}/transition.']], $this->json()['violations']);
        self::assertSame(422, $this->api('owner', 'PATCH', $uri, ['title' => '', 'priority' => 'high']));
        self::assertSame(400, $this->api('owner', 'PATCH', $uri, 'title=x'));

        // The assignee works on the task but does not own it.
        foreach ([['PATCH', ['title' => 'Taken over']], ['PATCH', ['assignee_id' => null]], ['DELETE', null]] as [$method, $body]) {
            self::assertSame(403, $this->api('editor', $method, $uri, $body));
            self::assertSame(['error' => 'Only the owner of the task can change or delete it.'], $this->json());
        }
        foreach (['viewer', 'unrelated'] as $persona) {
            self::assertSame(404, $this->api($persona, 'PATCH', $uri, ['title' => 'x']), $persona);
            self::assertSame(404, $this->api($persona, 'DELETE', $uri), $persona);
        }
        self::assertSame(401, $this->api(null, 'PATCH', $uri, ['title' => 'x']));
        self::assertSame(401, $this->api(null, 'DELETE', $uri, null, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]));
        self::assertSame(404, $this->api('owner', 'PATCH', '/api/tasks/not-an-id', ['title' => 'x']));

        self::assertSame(200, $this->api('owner', 'GET', $uri));
        self::assertSame(['Renamed', 'low', 2], [$this->json()['title'], $this->json()['priority'], $this->json()['version']]);

        self::assertSame(204, $this->api('owner', 'DELETE', $uri));
        self::assertSame(404, $this->api('owner', 'GET', $uri));
        self::assertSame(404, $this->api('editor', 'GET', $uri));
        self::assertSame(404, $this->api('owner', 'DELETE', $uri));
        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $task['id'], 'eventType' => AuditEvent::TASK_DELETED]));
    }

    public function testReorderIsAtomicAndRejectsAStaleColumn(): void
    {
        $ids = [];
        foreach (['A', 'B', 'C', 'D'] as $title) {
            $ids[$title] = $this->createTask('owner', ['title' => $title])['id'];
        }
        $foreign = $this->createTask('editor', ['title' => 'Editor backlog'])['id'];
        $assigned = $this->createTask('editor', ['title' => 'For the owner', 'assignee_id' => $this->userIds['owner']])['id'];

        $order = [$ids['C'], $ids['A'], strtoupper($ids['D']), $ids['B']];
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/reorder', ['status' => 'backlog', 'ordered_ids' => $order]));
        self::assertSame(['status', 'items'], array_keys($this->json()));
        self::assertSame([['C', 0], ['A', 1], ['D', 2], ['B', 3]], array_map(static fn (array $t): array => [$t['title'], $t['position']], $this->json()['items']));
        self::assertSame(['C', 'A', 'D', 'B'], array_column(array_filter($this->boardTasks('owner'), static fn (array $t): bool => 'owner' === $t['role']), 'title'));

        // The same order again is fine and changes nothing.
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/reorder', ['status' => 'backlog', 'ordered_ids' => $order]));
        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['actorId' => $this->userIds['owner'], 'eventType' => AuditEvent::TASK_REORDERED]));

        // The column is not what the client thinks it is.
        self::assertSame(200, $this->api('owner', 'POST', '/api/tasks/'.$ids['A'].'/transition', ['status' => 'todo']));
        $stale = [
            'a task moved away' => [$ids['A'], $ids['B'], $ids['C'], $ids['D']],
            'a task missing from the list' => [$ids['B'], $ids['C']],
            'an unknown task' => [$ids['B'], $ids['C'], $ids['D'], self::MISSING],
            "somebody else's task" => [$ids['B'], $ids['C'], $ids['D'], $foreign],
            'a task only assigned to me' => [$ids['B'], $ids['C'], $ids['D'], $assigned],
            'empty for a column with tasks' => [],
        ];
        foreach ($stale as $case => $list) {
            self::assertSame(409, $this->api('owner', 'PATCH', '/api/tasks/reorder', ['status' => 'backlog', 'ordered_ids' => $list]), $case);
            self::assertSame(['error' => 'The column changed. Reload the board and try again.'], $this->json());
        }
        self::assertSame(['C', 'D', 'B'], array_column(array_filter($this->boardTasks('owner'), static fn (array $t): bool => 'owner' === $t['role'] && 'backlog' === $t['status']), 'title'), 'refused reorders moved nothing');
        self::assertSame(0, $this->em()->find(Task::class, $foreign)->getPosition());

        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/reorder', ['status' => 'done', 'ordered_ids' => []]));

        $invalid = [[], ['status' => 'backlog'], ['ordered_ids' => []], ['status' => 'later', 'ordered_ids' => []], ['status' => 'backlog', 'ordered_ids' => 'all'],
            ['status' => 'backlog', 'ordered_ids' => [$ids['B'], $ids['B'], $ids['C'], $ids['D']]], ['status' => 'backlog', 'ordered_ids' => ['x']], ['status' => 'backlog', 'ordered_ids' => [1, 2]],
            ['status' => 'backlog', 'ordered_ids' => [$ids['B']], 'version' => 1]];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->api('owner', 'PATCH', '/api/tasks/reorder', $body), json_encode($body));
        }
        self::assertSame(400, $this->api('owner', 'PATCH', '/api/tasks/reorder', '['));
        self::assertSame(401, $this->api(null, 'PATCH', '/api/tasks/reorder', ['status' => 'backlog', 'ordered_ids' => []]));
    }

    public function testListFiltersAndBoardOrder(): void
    {
        $high = $this->createTask('owner', ['title' => 'High 100%', 'priority' => 'high', 'assignee_id' => $this->userIds['editor']]);
        $low = $this->createTask('owner', ['title' => 'Low one', 'priority' => 'low', 'description' => 'find_me here']);
        $mine = $this->createTask('owner', ['title' => 'Assigned to myself', 'assignee_id' => $this->userIds['owner']]);
        $theirs = $this->createTask('editor', ['title' => 'From the editor', 'assignee_id' => $this->userIds['owner']]);
        $this->api('owner', 'POST', '/api/tasks/'.$low['id'].'/transition', ['status' => 'todo']);
        $this->api('owner', 'POST', '/api/tasks/'.$low['id'].'/transition', ['status' => 'in_progress']);
        $this->api('owner', 'POST', '/api/tasks/'.$mine['id'].'/transition', ['status' => 'todo']);

        $cases = [
            '' => [$high['id'], $theirs['id'], $mine['id'], $low['id']],
            'status=backlog' => [$high['id'], $theirs['id']],
            'status=in_progress' => [$low['id']],
            'status=done' => [],
            'priority=high' => [$high['id']],
            'priority=medium&status=todo' => [$mine['id']],
            'assignee=me' => [$theirs['id'], $mine['id']],
            'assignee=none' => [$low['id']],
            'assignee='.$this->userIds['editor'] => [$high['id']],
            'q=FIND_ME' => [$low['id']],
            'q='.rawurlencode('100%') => [$high['id']],
            'q='.rawurlencode('%') => [$high['id']],
            'q=_' => [$low['id']],
            'q=nothing' => [],
            'limit=2' => [$high['id'], $theirs['id']],
            'limit=2&page=2' => [$mine['id'], $low['id']],
            'limit=2&page=3' => [],
        ];
        foreach ($cases as $query => $expected) {
            self::assertSame(200, $this->api('owner', 'GET', '/api/tasks?'.$query), $query);
            self::assertEqualsCanonicalizing($expected, array_column($this->json()['items'], 'id'), $query);
            if (!str_contains($query, 'limit')) {
                self::assertSame(count($expected), $this->json()['total'], $query);
            }
        }
        self::assertSame(200, $this->api('owner', 'GET', '/api/tasks?limit=2'));
        self::assertSame(['total' => 4, 'page' => 1, 'items_per_page' => 2], array_diff_key($this->json(), ['items' => 1]));
        self::assertSame(['backlog', 'backlog', 'todo', 'in_progress'], array_column($this->boardTasks('owner'), 'status'));

        foreach (['status=open', 'status[]=todo', 'priority=urgent', 'assignee=nobody', 'noteId=x', 'q='.str_repeat('a', 201), 'page=0', 'limit=0', 'limit=101'] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', '/api/tasks?'.$query), $query);
        }
        self::assertSame(401, $this->api(null, 'GET', '/api/tasks'));
        self::assertSame(401, $this->api(null, 'GET', '/api/tasks/assignees'));
    }

    public function testOpenApiDocumentsTaskRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        self::assertEqualsCanonicalizing(['201', '400', '401', '404', '422', '500'], array_map('strval', array_keys($paths['/api/tasks']['post']['responses'])));
        self::assertArrayHasKey('get', $paths['/api/tasks']);
        self::assertEqualsCanonicalizing(['get', 'patch', 'delete'], array_values(array_intersect(array_keys($paths['/api/tasks/{id}']), ['get', 'patch', 'delete', 'put', 'post'])));
        self::assertEqualsCanonicalizing(['200', '400', '401', '404', '409', '422', '500'], array_map('strval', array_keys($paths['/api/tasks/{id}/transition']['post']['responses'])));
        self::assertArrayHasKey('409', $paths['/api/tasks/reorder']['patch']['responses']);
        self::assertArrayHasKey('get', $paths['/api/tasks/assignees']);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function createTask(string $persona, array $fields): array
    {
        self::assertSame(201, $this->api($persona, 'POST', '/api/tasks', $fields), (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boardTasks(string $persona): array
    {
        self::assertSame(200, $this->api($persona, 'GET', '/api/tasks?limit=100'));

        return $this->json()['items'];
    }

    /**
     * @return list<array{string, string}> [title, status] in board order
     */
    private function board(string $persona): array
    {
        return array_map(static fn (array $task): array => [$task['title'], $task['status']], $this->boardTasks($persona));
    }
}
