<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\NoteRepository;
use App\Repository\TaskRepository;
use App\Security\NoteAccess;
use App\Service\AuditLogger;
use App\Service\Collaborators;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Tasks')]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class TaskController extends ApiController
{
    public const MAX_TITLE = 150;
    public const MAX_DESCRIPTION = 5000;

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;
    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?([Zz]|[+-]\d{2}:\d{2})$/D';
    private const EDITABLE = ['title', 'description', 'priority', 'due_at', 'assignee_id', 'note_id'];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly NoteRepository $notes,
        private readonly NoteAccess $access,
        private readonly Collaborators $collaborators,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/tasks', name: 'api_tasks_list', methods: ['GET'])]
    #[OA\Get(summary: 'List my tasks.', description: 'Tasks I own or am assigned to, in board order: by status (backlog, todo, in_progress, done), then position.')]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['backlog', 'todo', 'in_progress', 'done']))]
    #[OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high']))]
    #[OA\Parameter(name: 'assignee', description: '"me", "none", or a user ID.', in: 'query', schema: new OA\Schema(type: 'string', example: 'me'))]
    #[OA\Parameter(name: 'noteId', description: 'Only tasks linked to this note.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'q', description: 'Text in title or description; case-insensitive, taken literally.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200))]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Tasks.', content: new OA\JsonContent(
        required: ['items', 'total', 'page', 'items_per_page'],
        properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Task')),
            new OA\Property(property: 'total', type: 'integer', example: 20),
            new OA\Property(property: 'page', type: 'integer', example: 1),
            new OA\Property(property: 'items_per_page', type: 'integer', example: 50),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Invalid filter or pagination value.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function list(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $query = $request->query->all();
            $violations = [];
            $filters = [];

            $page = $this->validatedQueryInt($request, 'page', 1, 1, PHP_INT_MAX, $violations);
            $limit = $this->validatedQueryInt($request, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, $violations);
            foreach (['status' => TaskStatus::class, 'priority' => TaskPriority::class] as $name => $enum) {
                if (array_key_exists($name, $query)) {
                    $value = is_string($query[$name]) ? $enum::tryFrom($query[$name]) : null;
                    if (null === $value) {
                        $violations[] = ['propertyPath' => $name, 'message' => sprintf('%s must be one of: %s.', $name, implode(', ', $enum::values()))];
                    } else {
                        $filters[$name] = 'status' === $name ? $value : $value->value;
                    }
                }
            }
            if (array_key_exists('assignee', $query)) {
                $assignee = $query['assignee'];
                if ('me' === $assignee) {
                    $filters['assignee'] = (string) $user->getId();
                } elseif ('none' === $assignee || (is_string($assignee) && Uuid::isValid($assignee))) {
                    $filters['assignee'] = strtolower($assignee);
                } else {
                    $violations[] = ['propertyPath' => 'assignee', 'message' => 'assignee must be "me", "none" or a user ID.'];
                }
            }
            if (array_key_exists('noteId', $query)) {
                if (is_string($query['noteId']) && Uuid::isValid($query['noteId'])) {
                    $filters['note'] = strtolower($query['noteId']);
                } else {
                    $violations[] = ['propertyPath' => 'noteId', 'message' => 'noteId must be a note ID.'];
                }
            }
            if (array_key_exists('q', $query)) {
                if (is_string($query['q']) && mb_strlen($query['q']) <= 200) {
                    $filters['q'] = trim($query['q']);
                } else {
                    $violations[] = ['propertyPath' => 'q', 'message' => 'q must be a string of at most 200 characters.'];
                }
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $total = (int) $this->tasks->visibleTo($user, $filters)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
            $items = $this->tasks->visibleTo($user, $filters)
                ->addSelect("CASE t.status WHEN 'backlog' THEN 0 WHEN 'todo' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END AS HIDDEN statusOrder")
                ->orderBy('statusOrder', 'ASC')
                ->addOrderBy('t.position', 'ASC')
                ->addOrderBy('t.id', 'ASC')
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

            return $this->json([
                'items' => array_map(fn (Task $task): array => $this->taskData($task, $user), $items),
                'total' => $total,
                'page' => $page,
                'items_per_page' => $limit,
            ]);
        });
    }

    #[Route('/api/tasks/assignees', name: 'api_tasks_assignees', methods: ['GET'], priority: 10)]
    #[OA\Get(summary: 'List who I can assign tasks to.', description: 'Me and my collaborators: people I shared a note with and owners of notes shared with me. With "noteId", only those who can see that note.')]
    #[OA\Parameter(name: 'noteId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Possible assignees, me first.', content: new OA\JsonContent(
        required: ['items'],
        properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskPerson'))],
    ))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'The note is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
    public function assignees(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $people = $this->collaborators->of($user);
            if ($request->query->has('noteId')) {
                $note = $this->visibleNote($request->query->all()['noteId'], $user);
                if (null === $note) {
                    return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
                }
                $people = $this->collaborators->withAccessTo($user, $note, $this->access->canView(...));
            }

            return $this->json(['items' => array_map(self::person(...), $people)]);
        });
    }

    #[Route('/api/tasks', name: 'api_tasks_create', methods: ['POST'])]
    #[OA\Post(summary: 'Create a task.', description: 'The task starts in "backlog", at the end of that column. The assignee must be me or one of my collaborators and, when a note is linked, must be able to see that note.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaskWrite'))]
    #[OA\Response(response: Response::HTTP_CREATED, description: 'Task created.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'The linked note or the assignee is missing or not available to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Assignee not found.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'due_at', 'message' => 'Due time must be an ISO 8601 date-time with a time zone, e.g. 2026-10-11T08:30:00Z, or null.']]]))]
    public function create(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, self::EDITABLE);
            if (!array_key_exists('title', $payload)) {
                $violations[] = ['propertyPath' => 'title', 'message' => 'Title is required.'];
            }
            $task = new Task($user, '');
            $refusal = $this->applyFields($task, $payload, $user, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }
            if (null !== $refusal) {
                return $refusal;
            }

            $task->setPosition($this->tasks->nextPosition($user, TaskStatus::Backlog));
            $this->entityManager->persist($task);
            $this->audit->record(AuditEvent::TASK_CREATED, $user, $task->getNote()?->getId(), targetUserId: $task->getAssignee()?->getId(), subjectId: $task->getId());
            $this->entityManager->flush();

            return $this->json($this->taskData($task, $user), Response::HTTP_CREATED, ['Location' => '/api/tasks/'.$task->getId()]);
        });
    }

    #[Route('/api/tasks/reorder', name: 'api_tasks_reorder', methods: ['PATCH'], priority: 10)]
    #[OA\Patch(summary: 'Set the order of one column of my board.', description: 'Send the IDs of all my tasks in that status, in the wanted order. If the column no longer holds exactly these tasks (one was added, moved away or deleted meanwhile), nothing changes and the answer is 409. All positions are written in one transaction.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['status', 'ordered_ids'],
        properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['backlog', 'todo', 'in_progress', 'done'], example: 'todo'),
            new OA\Property(property: 'ordered_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Column reordered.', content: new OA\JsonContent(
        required: ['status', 'items'],
        properties: [
            new OA\Property(property: 'status', type: 'string', example: 'todo'),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Task')),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'The column changed since the client read it.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'The column changed. Reload the board and try again.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function reorder(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['status', 'ordered_ids']);
            $status = is_string($payload['status'] ?? null) ? TaskStatus::tryFrom($payload['status']) : null;
            if (null === $status) {
                $violations[] = ['propertyPath' => 'status', 'message' => 'Status must be one of: '.implode(', ', TaskStatus::values()).'.'];
            }
            $ids = $payload['ordered_ids'] ?? null;
            if (!is_array($ids) || !array_is_list($ids)) {
                $violations[] = ['propertyPath' => 'ordered_ids', 'message' => 'A list of task IDs is required.'];
                $ids = [];
            }
            foreach ($ids as $index => $id) {
                if (!is_string($id) || !Uuid::isValid($id)) {
                    $violations[] = ['propertyPath' => sprintf('ordered_ids[%d]', $index), 'message' => 'Each entry must be a task ID.'];
                }
            }
            if ([] === $violations && count(array_unique(array_map('strtolower', $ids))) !== count($ids)) {
                $violations[] = ['propertyPath' => 'ordered_ids', 'message' => 'A task ID is listed more than once.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }
            $ids = array_map('strtolower', $ids);

            $column = $this->entityManager->wrapInTransaction(function () use ($user, $status, $ids): ?array {
                $byId = [];
                foreach ($this->tasks->lockColumn($user, $status) as $task) {
                    $byId[$task->getId()] = $task;
                }
                // The client must have seen the column exactly as it is now.
                if (count($byId) !== count($ids) || [] !== array_diff($ids, array_keys($byId))) {
                    return null;
                }

                $ordered = [];
                $moved = false;
                foreach ($ids as $position => $id) {
                    $moved = $moved || $byId[$id]->getPosition() !== $position;
                    $ordered[] = $byId[$id]->setPosition($position);
                }
                if ($moved) {
                    $this->audit->record(AuditEvent::TASK_REORDERED, $user, null, metadata: ['status' => $status->value, 'count' => count($ids)]);
                }

                return $ordered;
            });

            if (null === $column) {
                return $this->error('The column changed. Reload the board and try again.', Response::HTTP_CONFLICT);
            }

            return $this->json(['status' => $status->value, 'items' => array_map(fn (Task $task): array => $this->taskData($task, $user), $column)]);
        });
    }

    #[Route('/api/tasks/{id}', name: 'api_tasks_show', methods: ['GET'])]
    #[OA\Get(summary: 'Get a task.', description: 'Its owner and its assignee.')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The task.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Task is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Task not found.']))]
    public function show(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $task = $this->visibleTask($id, $user);

            return null === $task ? $this->notFound() : $this->json($this->taskData($task, $user));
        });
    }

    #[Route('/api/tasks/{id}', name: 'api_tasks_update', methods: ['PATCH'])]
    #[OA\Patch(summary: 'Change a task.', description: 'Owner only. Send the fields to change; the status is changed with the transition route. Send "version" to detect concurrent changes.')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaskWrite'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Task updated.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'The caller is the assignee, not the owner.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Only the owner of the task can change or delete it.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Task, linked note or assignee is missing or not available to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Task not found.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'The task was changed by another request.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Task was changed by another request. Reload and try again.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $task = $this->visibleTask($id, $user);
            if (null === $task) {
                return $this->notFound();
            }
            if ($task->getOwner()->getId() !== $user->getId()) {
                return $this->ownerOnly();
            }

            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, [...self::EDITABLE, 'version']);
            if (array_key_exists('status', $payload)) {
                $violations[] = ['propertyPath' => 'status', 'message' => 'Change the status with POST /api/tasks/{id}/transition.'];
                $violations = array_values(array_filter($violations, static fn (array $v): bool => 'Unexpected field.' !== $v['message'] || 'status' !== $v['propertyPath']));
            }
            $expectedVersion = $this->validatedVersion($payload, $violations);
            $before = $this->snapshot($task);
            $refusal = $this->applyFields($task, $payload, $user, $violations);
            if ([] !== $violations || null !== $refusal) {
                $this->entityManager->refresh($task);

                return [] !== $violations ? $this->validationError($violations) : $refusal;
            }
            if (null !== $expectedVersion && $expectedVersion !== $task->getVersion()) {
                $this->entityManager->refresh($task);

                return $this->stale();
            }

            $changed = array_keys(array_diff_assoc($this->snapshot($task), $before));
            if ([] === $changed) {
                $this->entityManager->refresh($task);

                return $this->json($this->taskData($task, $user));
            }

            $this->audit->record(AuditEvent::TASK_UPDATED, $user, $task->getNote()?->getId(), targetUserId: $task->getAssignee()?->getId(), metadata: ['changed' => $changed], subjectId: $task->getId());
            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->stale();
            }

            return $this->json($this->taskData($task, $user));
        });
    }

    #[Route('/api/tasks/{id}/transition', name: 'api_tasks_transition', methods: ['POST'])]
    #[OA\Post(summary: 'Move a task to another status.', description: 'Owner and assignee. Allowed moves: backlog → todo; todo → backlog or in_progress; in_progress → todo or done; done → in_progress. Any other move, including to the status it already has, is a 409. The task goes to the end of the new column.')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['status'],
        properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['backlog', 'todo', 'in_progress', 'done'], example: 'in_progress'),
            new OA\Property(property: 'version', type: 'integer', minimum: 1, example: 1),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Task moved.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Task is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Task not found.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'The move is not allowed from the current status, or the task was changed by another request.', content: new OA\JsonContent(
        required: ['error'],
        properties: [
            new OA\Property(property: 'error', type: 'string', example: 'A task cannot move from backlog to done.'),
            new OA\Property(property: 'current_status', type: 'string', example: 'backlog'),
            new OA\Property(property: 'allowed', type: 'array', items: new OA\Items(type: 'string'), example: ['todo']),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function transition(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $task = $this->visibleTask($id, $user);
            if (null === $task) {
                return $this->notFound();
            }

            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['status', 'version']);
            $target = is_string($payload['status'] ?? null) ? TaskStatus::tryFrom($payload['status']) : null;
            if (null === $target) {
                $violations[] = ['propertyPath' => 'status', 'message' => 'Status must be one of: '.implode(', ', TaskStatus::values()).'.'];
            }
            $expectedVersion = $this->validatedVersion($payload, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }
            if (null !== $expectedVersion && $expectedVersion !== $task->getVersion()) {
                return $this->stale();
            }

            $from = $task->getStatus();
            if (!$from->canMoveTo($target)) {
                return $this->json([
                    'error' => sprintf('A task cannot move from %s to %s.', $from->value, $target->value),
                    'current_status' => $from->value,
                    'allowed' => array_map(static fn (TaskStatus $status): string => $status->value, $from->allowedTargets()),
                ], Response::HTTP_CONFLICT);
            }

            $task->setStatus($target)->setPosition($this->tasks->nextPosition($task->getOwner(), $target));
            $this->audit->record(AuditEvent::TASK_TRANSITIONED, $user, $task->getNote()?->getId(), metadata: ['from' => $from->value, 'to' => $target->value], subjectId: $task->getId());
            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->stale();
            }

            return $this->json($this->taskData($task, $user));
        });
    }

    #[Route('/api/tasks/{id}', name: 'api_tasks_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Delete a task.', description: 'Owner only.')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'Task deleted.')]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'The caller is the assignee, not the owner.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Only the owner of the task can change or delete it.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Task is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Task not found.']))]
    public function delete(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $task = $this->visibleTask($id, $user);
            if (null === $task) {
                return $this->notFound();
            }
            if ($task->getOwner()->getId() !== $user->getId()) {
                return $this->ownerOnly();
            }

            $this->audit->record(AuditEvent::TASK_DELETED, $user, $task->getNote()?->getId(), subjectId: $task->getId());
            $this->entityManager->remove($task);
            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->stale();
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * Validates the fields present in the payload and sets them on the task.
     *
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     *
     * @return JsonResponse|null a 404 for a note or assignee the caller cannot use
     */
    private function applyFields(Task $task, array $payload, User $user, array &$violations): ?JsonResponse
    {
        if (array_key_exists('title', $payload)) {
            $title = is_string($payload['title']) ? trim((string) preg_replace('/\s+/u', ' ', $payload['title'])) : null;
            if (null === $title || '' === $title) {
                $violations[] = ['propertyPath' => 'title', 'message' => is_string($payload['title']) || null === $payload['title'] ? 'Title is required.' : 'Title must be a string.'];
            } elseif (mb_strlen($title) > self::MAX_TITLE) {
                $violations[] = ['propertyPath' => 'title', 'message' => sprintf('Title cannot be longer than %d characters.', self::MAX_TITLE)];
            } else {
                $task->setTitle($title);
            }
        }

        if (array_key_exists('description', $payload)) {
            $description = $payload['description'] ?? '';
            if (!is_string($description)) {
                $violations[] = ['propertyPath' => 'description', 'message' => 'Description must be a string.'];
            } elseif (mb_strlen($description) > self::MAX_DESCRIPTION) {
                $violations[] = ['propertyPath' => 'description', 'message' => sprintf('Description cannot be longer than %d characters.', self::MAX_DESCRIPTION)];
            } else {
                $task->setDescription($description);
            }
        }

        if (array_key_exists('priority', $payload)) {
            $priority = is_string($payload['priority']) ? TaskPriority::tryFrom($payload['priority']) : null;
            if (null === $priority) {
                $violations[] = ['propertyPath' => 'priority', 'message' => 'Priority must be one of: '.implode(', ', TaskPriority::values()).'.'];
            } else {
                $task->setPriority($priority);
            }
        }

        if (array_key_exists('due_at', $payload)) {
            $dueAt = null === $payload['due_at'] ? null : self::parseDateTime($payload['due_at']);
            if (null !== $payload['due_at'] && null === $dueAt) {
                $violations[] = ['propertyPath' => 'due_at', 'message' => 'Due time must be an ISO 8601 date-time with a time zone, e.g. 2026-10-11T08:30:00Z, or null.'];
            } else {
                $task->setDueAt($dueAt);
            }
        }

        foreach (['note_id', 'assignee_id'] as $field) {
            if (array_key_exists($field, $payload) && null !== $payload[$field] && (!is_string($payload[$field]) || !Uuid::isValid($payload[$field]))) {
                $violations[] = ['propertyPath' => $field, 'message' => sprintf('%s must be an ID or null.', 'note_id' === $field ? 'Note ID' : 'Assignee ID')];
            }
        }
        if ([] !== $violations) {
            return null;
        }

        if (array_key_exists('note_id', $payload)) {
            $note = null === $payload['note_id'] ? null : $this->visibleNote($payload['note_id'], $user);
            if (null !== $payload['note_id'] && null === $note) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }
            $task->setNote($note);
        }

        if (array_key_exists('assignee_id', $payload)) {
            $assignee = null === $payload['assignee_id'] ? null : $this->collaborators->find($user, strtolower($payload['assignee_id']));
            if (null !== $payload['assignee_id'] && null === $assignee) {
                // Somebody the caller does not work with is no different from nobody.
                return $this->error('Assignee not found.', Response::HTTP_NOT_FOUND);
            }
            $task->setAssignee($assignee);
        }

        // Whichever of the two was just set, they must fit together.
        $note = $task->getNote();
        $assignee = $task->getAssignee();
        if (null !== $note && null !== $assignee && (array_key_exists('note_id', $payload) || array_key_exists('assignee_id', $payload)) && !$this->access->canView($note, $assignee)) {
            $violations[] = ['propertyPath' => 'assignee_id', 'message' => 'The assignee cannot see the linked note. Share the note with them first.'];
        }

        return null;
    }

    private static function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || 1 !== preg_match(self::DATE_PATTERN, $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $m[4] > 23 || $m[5] > 59 || $m[6] > 59) {
            return null;
        }

        try {
            // Stored in UTC, to the second.
            $utc = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));

            return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(Task $task): array
    {
        return [
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'priority' => $task->getPriority()->value,
            'due_at' => $task->getDueAt()?->format(\DATE_ATOM),
            'assignee_id' => $task->getAssignee()?->getId(),
            'note_id' => $task->getNote()?->getId(),
        ];
    }

    private function visibleTask(string $id, User $user): ?Task
    {
        $task = Uuid::isValid($id) ? $this->tasks->find($id) : null;
        if (null === $task || ($task->getOwner()->getId() !== $user->getId() && $task->getAssignee()?->getId() !== $user->getId())) {
            return null;
        }

        return $task;
    }

    private function visibleNote(mixed $id, User $user): ?Note
    {
        $note = is_string($id) && Uuid::isValid($id) ? $this->notes->find($id) : null;

        return null !== $note && $this->access->canView($note, $user) ? $note : null;
    }

    private function notFound(): JsonResponse
    {
        return $this->error('Task not found.', Response::HTTP_NOT_FOUND);
    }

    private function ownerOnly(): JsonResponse
    {
        return $this->error('Only the owner of the task can change or delete it.', Response::HTTP_FORBIDDEN);
    }

    private function stale(): JsonResponse
    {
        return $this->error('Task was changed by another request. Reload and try again.', Response::HTTP_CONFLICT);
    }

    /**
     * @return array{id: string, email: string}
     */
    private static function person(User $user): array
    {
        return ['id' => (string) $user->getId(), 'email' => $user->getEmail()];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskData(Task $task, User $viewer): array
    {
        $utc = new \DateTimeZone('UTC');
        $note = $task->getNote();

        return [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => $task->getStatus()->value,
            'priority' => $task->getPriority()->value,
            'due_at' => $task->getDueAt()?->setTimezone($utc)->format(\DATE_ATOM),
            'position' => $task->getPosition(),
            'version' => $task->getVersion(),
            'role' => $task->getOwner()->getId() === $viewer->getId() ? 'owner' : 'assignee',
            'owner' => self::person($task->getOwner()),
            'assignee' => null === $task->getAssignee() ? null : self::person($task->getAssignee()),
            // The link is shown only to those who can open the note.
            'note' => null !== $note && $this->access->canView($note, $viewer) ? ['id' => (string) $note->getId(), 'title' => $note->getTitle()] : null,
            'allowed_statuses' => array_map(static fn (TaskStatus $status): string => $status->value, $task->getStatus()->allowedTargets()),
            'created_at' => $task->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
            'updated_at' => $task->getUpdatedAt()->setTimezone($utc)->format(\DATE_ATOM),
        ];
    }
}
