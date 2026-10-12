<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\RealtimeEvent;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\NoteRevisionRepository;
use App\Repository\UserRepository;
use App\Security\NoteAccess;
use App\Service\AuditLogger;
use App\Service\NoteRevisions;
use App\Service\Realtime;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Note Version History')]
#[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'The note is missing or not visible to the caller, or it has no such revision.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Revision not found.']))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class NoteRevisionController extends ApiController
{
    private const DEFAULT_ITEMS_PER_PAGE = 20;
    private const MAX_ITEMS_PER_PAGE = 100;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly NoteRevisionRepository $revisions,
        private readonly UserRepository $users,
        private readonly NoteAccess $access,
        private readonly NoteRevisions $history,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
        private readonly Realtime $realtime,
    ) {
    }

    #[Route('/api/notes/{id}/revisions', name: 'api_note_revisions_list', methods: ['GET'])]
    #[OA\Get(summary: 'List the revisions of a note.', description: 'Anyone who can see the note. Newest first, without the content. The 100 latest revisions are kept.')]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'itemsPerPage', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Revisions.', content: new OA\JsonContent(
        required: ['items', 'total', 'page', 'items_per_page', 'current_version'],
        properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/NoteRevisionSummary')),
            new OA\Property(property: 'total', type: 'integer', example: 3),
            new OA\Property(property: 'page', type: 'integer', example: 1),
            new OA\Property(property: 'items_per_page', type: 'integer', example: 20),
            new OA\Property(property: 'current_version', description: 'Version of the note right now.', type: 'integer', example: 3),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Invalid pagination parameters.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function list(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $note = $this->visibleNote($id, $user);
            if (null === $note) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }

            $violations = [];
            $page = $this->validatedQueryInt($request, 'page', 1, 1, PHP_INT_MAX, $violations);
            $itemsPerPage = $this->validatedQueryInt($request, 'itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE, 1, self::MAX_ITEMS_PER_PAGE, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            return $this->json([
                'items' => $this->revisionData($this->revisions->findPage($note, $page, $itemsPerPage), false),
                'total' => $this->revisions->countByNote($note),
                'page' => $page,
                'items_per_page' => $itemsPerPage,
                'current_version' => $note->getVersion(),
            ]);
        });
    }

    #[Route('/api/notes/{id}/revisions/{rev}', name: 'api_note_revisions_show', methods: ['GET'])]
    #[OA\Get(summary: 'Get one revision with its title and content.', description: 'Anyone who can see the note.')]
    #[OA\Parameter(name: 'rev', description: 'Revision number.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The revision.', content: new OA\JsonContent(ref: '#/components/schemas/NoteRevision'))]
    public function show(string $id, string $rev): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $rev): JsonResponse {
            $note = $this->visibleNote($id, $user);
            if (null === $note) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }

            $revision = $this->findRevision($note, $rev);
            if (null === $revision) {
                return $this->error('Revision not found.', Response::HTTP_NOT_FOUND);
            }

            return $this->json($this->revisionData([$revision], true)[0]);
        });
    }

    #[Route('/api/notes/{id}/revisions/{rev}/restore', name: 'api_note_revisions_restore', methods: ['POST'])]
    #[OA\Post(summary: 'Restore a revision.', description: 'Owner and editors. The note gets the title and content of that revision as a new revision; history is never rewritten. Requires If-Match with the current version of the note. Restoring the text the note already has changes nothing.')]
    #[OA\Parameter(name: 'rev', description: 'Revision number to restore.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'If-Match', description: 'Current version of the note, as given in its ETag.', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"3"'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Restored. The note is now at "version".', content: new OA\JsonContent(
        required: ['note_id', 'version', 'restored_from', 'title', 'content'],
        properties: [
            new OA\Property(property: 'note_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'version', description: 'New version of the note (unchanged if nothing had to change).', type: 'integer', example: 4),
            new OA\Property(property: 'restored_from', type: 'integer', example: 1),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'content', type: 'string'),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'The caller can only view the note.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Your role does not allow restoring revisions of this note.']))]
    #[OA\Response(response: Response::HTTP_PRECONDITION_FAILED, description: 'The note has changed since the version named in If-Match.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'The note was changed since the version you looked at. Reload it and try again.']))]
    #[OA\Response(response: Response::HTTP_PRECONDITION_REQUIRED, description: 'No If-Match header.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Send the current version of the note in an If-Match header.']))]
    public function restore(string $id, string $rev, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $rev, $request): JsonResponse {
            $note = $this->visibleNote($id, $user);
            if (null === $note) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }
            if (!$this->access->canEdit($note, $user)) {
                return $this->error('Your role does not allow restoring revisions of this note.', Response::HTTP_FORBIDDEN);
            }

            $revision = $this->findRevision($note, $rev);
            if (null === $revision) {
                return $this->error('Revision not found.', Response::HTTP_NOT_FOUND);
            }

            $ifMatch = trim((string) $request->headers->get('If-Match', ''));
            if ('' === $ifMatch) {
                return $this->error('Send the current version of the note in an If-Match header.', Response::HTTP_PRECONDITION_REQUIRED);
            }
            $stale = $this->error('The note was changed since the version you looked at. Reload it and try again.', Response::HTTP_PRECONDITION_FAILED);
            try {
                $expected = NoteRevisions::expectedVersion($ifMatch);
            } catch (PreconditionFailedHttpException) {
                return $stale;
            }
            if (null !== $expected && $expected !== $note->getVersion()) {
                return $stale;
            }

            if ($revision->getTitle() !== $note->getTitle() || $revision->getContent() !== $note->getContent()) {
                $from = $note->getVersion();
                $claimed = $this->entityManager->wrapInTransaction(function () use ($note, $revision, $user, $from): bool {
                    if (!$this->history->claimNext($note, $from)) {
                        return false;
                    }

                    $note->setVersion($from + 1)->setTitle($revision->getTitle())->setContent($revision->getContent());
                    $this->history->record($note, NoteRevision::RESTORED, $user, $revision->getRevision());
                    $this->audit->record(AuditEvent::NOTE_RESTORED, $user, (string) $note->getId(), metadata: ['from_revision' => $revision->getRevision(), 'new_revision' => $from + 1]);

                    return true;
                });
                if (!$claimed) {
                    return $stale;
                }
                $this->realtime->publish((string) $note->getId(), RealtimeEvent::NOTE_UPDATED, ['version' => $note->getVersion(), 'actor' => ['id' => (string) $user->getId(), 'email' => $user->getEmail()]]);
            }

            return $this->json(
                [
                    'note_id' => (string) $note->getId(),
                    'version' => $note->getVersion(),
                    'restored_from' => $revision->getRevision(),
                    'title' => $note->getTitle(),
                    'content' => $note->getContent(),
                ],
                Response::HTTP_OK,
                ['ETag' => NoteRevisions::etag($note)],
            );
        });
    }

    private function visibleNote(string $id, User $user): ?Note
    {
        $note = Uuid::isValid($id) ? $this->notes->find($id) : null;

        return null !== $note && $this->access->canView($note, $user) ? $note : null;
    }

    private function findRevision(Note $note, string $rev): ?NoteRevision
    {
        return 1 === preg_match('/^[1-9]\d{0,8}$/D', $rev) ? $this->revisions->findOneByNumber($note, (int) $rev) : null;
    }

    /**
     * @param list<NoteRevision> $revisions
     *
     * @return list<array<string, mixed>>
     */
    private function revisionData(array $revisions, bool $withContent): array
    {
        $actorIds = array_values(array_unique(array_filter(array_map(static fn (NoteRevision $r): ?string => $r->getActorId(), $revisions))));
        $emails = [];
        foreach ([] === $actorIds ? [] : $this->users->findBy(['id' => $actorIds]) as $actor) {
            $emails[$actor->getId()] = $actor->getEmail();
        }

        $utc = new \DateTimeZone('UTC');

        return array_map(static function (NoteRevision $revision) use ($emails, $utc, $withContent): array {
            $data = [
                'revision' => $revision->getRevision(),
                'kind' => $revision->getKind(),
                'restored_from' => $revision->getRestoredFrom(),
                'title' => $revision->getTitle(),
                // The account may be gone by now; the history stays.
                'actor' => null === $revision->getActorId() ? null : ['id' => $revision->getActorId(), 'email' => $emails[$revision->getActorId()] ?? null],
                'created_at' => $revision->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
            ];

            return $withContent ? $data + ['content' => $revision->getContent()] : $data;
        }, $revisions);
    }
}
