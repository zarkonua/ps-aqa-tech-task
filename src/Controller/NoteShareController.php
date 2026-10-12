<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Repository\NoteRepository;
use App\Repository\NoteShareRepository;
use App\Repository\UserRepository;
use App\Security\NoteAccess;
use App\Service\AuditLogger;
use App\Util\Uuid;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Note Sharing')]
#[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(
    response: Response::HTTP_FORBIDDEN,
    description: 'The caller can see the note but does not own it (editor or viewer).',
    content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Only the note owner can manage sharing.']),
)]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class NoteShareController extends ApiController
{
    private const MAX_EMAIL_LENGTH = 190;
    private const DEFAULT_ITEMS_PER_PAGE = 50;
    private const MAX_ITEMS_PER_PAGE = 100;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly NoteShareRepository $shares,
        private readonly UserRepository $users,
        private readonly NoteAccess $access,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notes/{id}/shares', name: 'api_note_shares_create', methods: ['POST'])]
    #[OA\Post(summary: 'Share a note with a registered user.', description: 'Owner only. The user is looked up by email; each user can hold at most one share per note.')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'role'],
            properties: [
                new OA\Property(property: 'email', description: 'Email of a registered, confirmed user.', type: 'string', format: 'email', maxLength: 190, example: 'viewer@example.com'),
                new OA\Property(property: 'role', type: 'string', enum: ['viewer', 'editor'], example: 'viewer'),
            ],
            additionalProperties: false,
        ),
    )]
    #[OA\Response(response: Response::HTTP_CREATED, description: 'Share created.', content: new OA\JsonContent(ref: '#/components/schemas/NoteShare'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible to the caller, or no registered user has this email.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'User not found.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'The note is already shared with this user.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note is already shared with this user.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function create(string $id, Request $request): JsonResponse
    {
        return $this->handle($id, function (Note $note, User $actor) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['email', 'role']);
            $email = $this->validatedEmail($payload, $violations);
            $role = $this->validatedRole($payload, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $target = $this->users->findOneByEmail($email);
            if (null === $target || !$target->isVerified()) {
                return $this->error('User not found.', Response::HTTP_NOT_FOUND);
            }

            if ($this->access->isOwner($note, $target)) {
                return $this->validationError([['propertyPath' => 'email', 'message' => 'A note cannot be shared with its owner.']]);
            }

            if (null !== $this->shares->findOneByNoteAndUser($note, $target)) {
                return $this->error('Note is already shared with this user.', Response::HTTP_CONFLICT);
            }

            $share = new NoteShare($note, $target, $role);
            $this->entityManager->persist($share);
            $this->audit->record(AuditEvent::SHARE_GRANTED, $actor, (string) $note->getId(), $share->getId(), $target->getId(), ['role' => $role->value]);

            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the same share first.
                return $this->error('Note is already shared with this user.', Response::HTTP_CONFLICT);
            }

            return $this->json($this->shareData($share), Response::HTTP_CREATED, [
                'Location' => sprintf('/api/notes/%s/shares/%s', $note->getId(), $share->getId()),
            ]);
        });
    }

    #[Route('/api/notes/{id}/shares', name: 'api_note_shares_list', methods: ['GET'])]
    #[OA\Get(summary: 'List the shares of a note.', description: 'Owner only. Ordered by creation time.')]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'itemsPerPage', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Shares of the note.', content: new OA\JsonContent(ref: '#/components/schemas/NoteShareList'))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Invalid pagination parameters.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function list(string $id, Request $request): JsonResponse
    {
        return $this->handle($id, function (Note $note) use ($request): JsonResponse {
            $violations = [];
            $page = $this->validatedQueryInt($request, 'page', 1, 1, PHP_INT_MAX, $violations);
            $itemsPerPage = $this->validatedQueryInt($request, 'itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE, 1, self::MAX_ITEMS_PER_PAGE, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            return $this->json([
                'items' => array_map($this->shareData(...), $this->shares->findPageByNote($note, $page, $itemsPerPage)),
                'total' => $this->shares->countByNote($note),
                'page' => $page,
                'items_per_page' => $itemsPerPage,
            ]);
        });
    }

    #[Route('/api/notes/{id}/shares/{shareId}', name: 'api_note_shares_update', methods: ['PATCH'])]
    #[OA\Patch(summary: 'Change the role of a share.', description: 'Owner only. Send the last seen "version" to detect concurrent changes.')]
    #[OA\Parameter(name: 'shareId', description: 'Share ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['role'],
            properties: [
                new OA\Property(property: 'role', type: 'string', enum: ['viewer', 'editor'], example: 'editor'),
                new OA\Property(property: 'version', description: 'Version the client last read; the update is rejected with 409 if it is stale.', type: 'integer', minimum: 1, example: 1),
            ],
            additionalProperties: false,
        ),
    )]
    #[OA\Response(response: Response::HTTP_OK, description: 'Share updated (or already had this role).', content: new OA\JsonContent(ref: '#/components/schemas/NoteShare'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note or share is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Share not found.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'The share was changed by another request.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Share was changed by another request. Reload and try again.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function update(string $id, string $shareId, Request $request): JsonResponse
    {
        return $this->handle($id, function (Note $note, User $actor) use ($shareId, $request): JsonResponse {
            $share = $this->findShare($note, $shareId);
            if (null === $share) {
                return $this->error('Share not found.', Response::HTTP_NOT_FOUND);
            }

            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['role', 'version']);
            $role = $this->validatedRole($payload, $violations);
            $expectedVersion = $this->validatedVersion($payload, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            if (null !== $expectedVersion && $expectedVersion !== $share->getVersion()) {
                return $this->staleShareError();
            }

            $previousRole = $share->getRole();
            if ($previousRole === $role) {
                return $this->json($this->shareData($share));
            }

            $share->setRole($role);
            $this->audit->record(
                AuditEvent::SHARE_ROLE_CHANGED,
                $actor,
                (string) $note->getId(),
                $share->getId(),
                $share->getUser()->getId(),
                ['from' => $previousRole->value, 'to' => $role->value],
            );

            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->staleShareError();
            }

            return $this->json($this->shareData($share));
        });
    }

    #[Route('/api/notes/{id}/shares/{shareId}', name: 'api_note_shares_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Revoke a share.', description: 'Owner only. Access is removed immediately.')]
    #[OA\Parameter(name: 'shareId', description: 'Share ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'Share revoked.')]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note or share is missing (or already revoked) or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Share not found.']))]
    public function delete(string $id, string $shareId): JsonResponse
    {
        return $this->handle($id, function (Note $note, User $actor) use ($shareId): JsonResponse {
            $share = $this->findShare($note, $shareId);
            if (null === $share) {
                return $this->error('Share not found.', Response::HTTP_NOT_FOUND);
            }

            $this->audit->record(
                AuditEvent::SHARE_REVOKED,
                $actor,
                (string) $note->getId(),
                $share->getId(),
                $share->getUser()->getId(),
                ['role' => $share->getRole()->value],
            );
            $this->entityManager->remove($share);

            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                // Revoked or changed by a concurrent request in the meantime.
                return $this->staleShareError();
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * Resolves the note and enforces "owner only" before running the action.
     * A note the caller cannot see is reported as missing, so its existence and shares cannot be probed.
     *
     * @param \Closure(Note, User): JsonResponse $action
     */
    private function handle(string $noteId, \Closure $action): JsonResponse
    {
        return $this->guarded(function (User $user) use ($noteId, $action): JsonResponse {
            $note = Uuid::isValid($noteId) ? $this->notes->find($noteId) : null;
            if (null === $note || !$this->access->canView($note, $user)) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }

            if (!$this->access->isOwner($note, $user)) {
                return $this->error('Only the note owner can manage sharing.', Response::HTTP_FORBIDDEN);
            }

            return $action($note, $user);
        });
    }

    private function findShare(Note $note, string $shareId): ?NoteShare
    {
        if (!Uuid::isValid($shareId)) {
            return null;
        }

        return $this->shares->findOneBy(['id' => $shareId, 'note' => $note]);
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function validatedEmail(array $payload, array &$violations): string
    {
        $email = $payload['email'] ?? null;
        if (null !== $email && !is_string($email)) {
            $violations[] = ['propertyPath' => 'email', 'message' => 'Email must be a string.'];

            return '';
        }

        $email = strtolower(trim((string) $email));
        if ('' === $email) {
            $violations[] = ['propertyPath' => 'email', 'message' => 'Email is required.'];
        } elseif (strlen($email) > self::MAX_EMAIL_LENGTH) {
            $violations[] = ['propertyPath' => 'email', 'message' => 'Email cannot be longer than 190 characters.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $violations[] = ['propertyPath' => 'email', 'message' => 'Invalid email format.'];
        }

        return $email;
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function validatedRole(array $payload, array &$violations): ShareRole
    {
        $value = $payload['role'] ?? null;
        $role = is_string($value) ? ShareRole::tryFrom($value) : null;

        if (null === $value) {
            $violations[] = ['propertyPath' => 'role', 'message' => 'Role is required.'];
        } elseif (!is_string($value)) {
            $violations[] = ['propertyPath' => 'role', 'message' => 'Role must be a string.'];
        } elseif (null === $role) {
            $violations[] = ['propertyPath' => 'role', 'message' => 'Role must be one of: viewer, editor.'];
        }

        return $role ?? ShareRole::Viewer;
    }

    private function staleShareError(): JsonResponse
    {
        return $this->error('Share was changed by another request. Reload and try again.', Response::HTTP_CONFLICT);
    }

    /**
     * @return array<string, mixed>
     */
    private function shareData(NoteShare $share): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'id' => $share->getId(),
            'note_id' => (string) $share->getNote()->getId(),
            'user' => [
                'id' => (string) $share->getUser()->getId(),
                'email' => $share->getUser()->getEmail(),
            ],
            'role' => $share->getRole()->value,
            'version' => $share->getVersion(),
            'created_at' => $share->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
            'updated_at' => $share->getUpdatedAt()->setTimezone($utc)->format(\DATE_ATOM),
        ];
    }
}
