<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\UserRepository;
use App\Security\NoteAccess;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Audit Log')]
#[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'The cursor is not one this API handed out.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid cursor.']))]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Invalid limit or filter value.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class AuditController extends ApiController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /** Event types that say who a note is shared with: only its owner sees them in the note's activity. */
    private const OWNER_ONLY_PREFIX = 'share.';

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly UserRepository $users,
        private readonly NoteAccess $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notes/{id}/activity', name: 'api_note_activity', methods: ['GET'])]
    #[OA\Get(summary: 'Read what happened to a note.', description: 'Everyone who can currently see the note; newest first. Sharing events are shown to the owner only. Events describe changes without the changed text. Once a note is deleted its activity is no longer available here.')]
    #[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'cursor', description: 'Value of "next_cursor" from the previous page.', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'One page of events.', content: new OA\JsonContent(ref: '#/components/schemas/AuditEventPage'))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing, deleted or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
    public function noteActivity(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $note = Uuid::isValid($id) ? $this->notes->find($id) : null;
            if (!$note instanceof Note || !$this->access->canView($note, $user)) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }

            $queryBuilder = $this->events()->andWhere('a.noteId = :note')->setParameter('note', (string) $note->getId())
                // Tasks are private to their owner and assignee, whoever can read the note.
                ->andWhere('a.eventType NOT LIKE :tasks')->setParameter('tasks', 'task.%');
            if (!$this->access->isOwner($note, $user)) {
                $queryBuilder->andWhere('a.eventType NOT LIKE :sharing')->setParameter('sharing', self::OWNER_ONLY_PREFIX.'%');
            }

            return $this->page($queryBuilder, $request, []);
        });
    }

    #[Route('/api/audit-events', name: 'api_audit_events', methods: ['GET'])]
    #[OA\Get(summary: 'Read the full audit feed.', description: 'Administrators only; newest first. Includes events about deleted notes and about accounts.')]
    #[OA\Parameter(name: 'cursor', description: 'Value of "next_cursor" from the previous page.', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1))]
    #[OA\Parameter(name: 'type', description: 'Exact event type, or a group such as "share." (with the dot).', in: 'query', schema: new OA\Schema(type: 'string', example: 'share.granted'))]
    #[OA\Parameter(name: 'actorId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'noteId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'correlationId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'One page of events.', content: new OA\JsonContent(ref: '#/components/schemas/AuditEventPage'))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'The caller is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Only administrators can read the audit feed.']))]
    public function feed(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            if (!$user->isAdmin()) {
                return $this->error('Only administrators can read the audit feed.', Response::HTTP_FORBIDDEN);
            }

            $queryBuilder = $this->events();
            $query = $request->query->all();
            $violations = [];
            foreach (['actorId' => 'a.actorId', 'noteId' => 'a.noteId'] as $name => $field) {
                if (array_key_exists($name, $query)) {
                    if (!is_string($query[$name]) || !Uuid::isValid($query[$name])) {
                        $violations[] = ['propertyPath' => $name, 'message' => $name.' must be an ID.'];
                    } else {
                        $queryBuilder->andWhere($field.' = :'.$name)->setParameter($name, strtolower($query[$name]));
                    }
                }
            }
            if (array_key_exists('type', $query)) {
                if (!is_string($query['type']) || 1 !== preg_match('/^[a-z_]{1,32}\.[a-z_]{0,32}$/D', $query['type'])) {
                    $violations[] = ['propertyPath' => 'type', 'message' => 'type must be an event type such as "share.granted", or a group such as "share.".'];
                } elseif (str_ends_with($query['type'], '.')) {
                    $queryBuilder->andWhere('a.eventType LIKE :type')->setParameter('type', $query['type'].'%');
                } else {
                    $queryBuilder->andWhere('a.eventType = :type')->setParameter('type', $query['type']);
                }
            }
            if (array_key_exists('correlationId', $query)) {
                if (!is_string($query['correlationId']) || 1 !== preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $query['correlationId'])) {
                    $violations[] = ['propertyPath' => 'correlationId', 'message' => 'correlationId must be 8 to 64 letters, digits, dots, dashes or underscores.'];
                } else {
                    $queryBuilder->andWhere('a.correlationId = :correlation')->setParameter('correlation', $query['correlationId']);
                }
            }

            return $this->page($queryBuilder, $request, $violations);
        });
    }

    private function events(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('a')->from(AuditEvent::class, 'a');
    }

    /**
     * Newest first, continued from a cursor that names the last event of the previous page.
     * Events are ordered by the microsecond they were recorded at, never changed, and only added
     * at the newest end, so pages never overlap or skip.
     *
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function page(QueryBuilder $queryBuilder, Request $request, array $violations): JsonResponse
    {
        $limit = $this->validatedQueryInt($request, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, $violations);
        if ([] !== $violations) {
            return $this->validationError($violations);
        }

        if ($request->query->has('cursor')) {
            $after = self::decodeCursor($request->query->all()['cursor']);
            if (null === $after) {
                return $this->error('Invalid cursor.');
            }
            $queryBuilder
                ->andWhere('(a.sequence < :cursorSequence OR (a.sequence = :cursorSequence AND a.id < :cursorId))')
                ->setParameter('cursorSequence', $after[0])
                ->setParameter('cursorId', $after[1]);
        }

        $events = $queryBuilder
            ->orderBy('a.sequence', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();

        $more = count($events) > $limit;
        $events = array_slice($events, 0, $limit);
        $last = [] === $events ? null : $events[count($events) - 1];

        return $this->json([
            'items' => $this->eventData($events),
            'next_cursor' => $more && null !== $last ? self::encodeCursor($last) : null,
            'limit' => $limit,
        ]);
    }

    private static function encodeCursor(AuditEvent $event): string
    {
        return rtrim(strtr(base64_encode($event->getSequence().'|'.$event->getId()), '+/', '-_'), '=');
    }

    /**
     * @return array{string, string}|null sequence and ID of the last event already delivered
     */
    private static function decodeCursor(mixed $cursor): ?array
    {
        if (!is_string($cursor) || '' === $cursor || strlen($cursor) > 120 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $decoded || 1 !== preg_match('/^(\d{1,18})\|(.+)$/sD', $decoded, $m) || !Uuid::isValid($m[2])) {
            return null;
        }

        return [$m[1], strtolower($m[2])];
    }

    /**
     * @param list<AuditEvent> $events
     *
     * @return list<array<string, mixed>>
     */
    private function eventData(array $events): array
    {
        $userIds = [];
        foreach ($events as $event) {
            $userIds[] = $event->getActorId();
            $userIds[] = $event->getTargetUserId();
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        $emails = [];
        foreach ([] === $userIds ? [] : $this->users->findBy(['id' => $userIds]) as $person) {
            $emails[$person->getId()] = $person->getEmail();
        }
        $person = static fn (?string $id): ?array => null === $id ? null : ['id' => $id, 'email' => $emails[$id] ?? null];
        $utc = new \DateTimeZone('UTC');

        return array_map(static fn (AuditEvent $event): array => [
            'id' => $event->getId(),
            'type' => $event->getEventType(),
            'actor' => $person($event->getActorId()),
            'target_user' => $person($event->getTargetUserId()),
            'note_id' => $event->getNoteId(),
            'subject_id' => $event->getSubjectId() ?? $event->getShareId(),
            // A summary of the change: names of fields, lengths, roles, statuses. Never the text itself.
            'details' => (object) $event->getMetadata(),
            'correlation_id' => $event->getCorrelationId(),
            'created_at' => $event->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
        ], $events);
    }
}
