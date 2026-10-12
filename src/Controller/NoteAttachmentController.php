<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteAttachment;
use App\Entity\User;
use App\Repository\NoteAttachmentRepository;
use App\Repository\NoteRepository;
use App\Security\NoteAccess;
use App\Service\AttachmentPolicy;
use App\Service\AttachmentStorage;
use App\Service\AuditLogger;
use App\Service\Throttle;
use App\Util\Uuid;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Note Attachments')]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class NoteAttachmentController extends ApiController
{
    public const UPLOADS_PER_MINUTE = 30;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly NoteAttachmentRepository $attachments,
        private readonly NoteAccess $access,
        private readonly AttachmentStorage $storage,
        private readonly Throttle $throttle,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notes/{id}/attachments', name: 'api_note_attachments_upload', methods: ['POST'])]
    #[OA\Post(summary: 'Attach a file to a note.', description: 'Owner and editors. One file per request, in the multipart field "file". Allowed: PNG, JPG, PDF, TXT, CSV, up to 10 MiB, at most 5 files per note. The type is taken from the file content and must match the extension; the Content-Type sent with the part is ignored. Limit: 30 uploads per minute.')]
    #[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
        required: ['file'],
        properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')],
    )))]
    #[OA\Response(response: Response::HTTP_CREATED, description: 'File attached.', content: new OA\JsonContent(ref: '#/components/schemas/NoteAttachment'))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'The caller can only view the note.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Your role does not allow changing the attachments of this note.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
    #[OA\Response(response: Response::HTTP_REQUEST_ENTITY_TOO_LARGE, description: 'File is larger than 10 MiB.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'File is larger than 10 MiB.']))]
    #[OA\Response(response: Response::HTTP_UNSUPPORTED_MEDIA_TYPE, description: 'Not an allowed file type, or the content does not match the extension.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Only PNG, JPG, PDF, TXT and CSV files are allowed, and the content must match the extension.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'No file, an empty file, several files, unexpected fields, or the note already has 5 attachments.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'file', 'message' => 'A note can have at most 5 attachments.']]]))]
    #[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'More than 30 uploads in a minute.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
    public function upload(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $note = $this->findNote($id, $user);
            if (!$note instanceof Note) {
                return $note;
            }
            if (!$this->access->canEdit($note, $user)) {
                return $this->error('Your role does not allow changing the attachments of this note.', Response::HTTP_FORBIDDEN);
            }

            // A body over PHP's own limit arrives with no fields at all.
            $declaredLength = (int) $request->headers->get('Content-Length', '0');
            $file = $request->files->get('file');
            if (null === $file && $declaredLength > AttachmentPolicy::MAX_BYTES && 0 === $request->request->count() && 0 === $request->files->count()) {
                return $this->tooLarge();
            }

            $violations = [];
            foreach (array_diff([...$request->files->keys(), ...$request->request->keys()], ['file']) as $field) {
                $violations[] = ['propertyPath' => mb_substr((string) $field, 0, 64), 'message' => 'Unexpected field.'];
            }
            if (!$file instanceof UploadedFile) {
                $violations[] = ['propertyPath' => 'file', 'message' => null === $file ? 'A file is required in the multipart field "file".' : 'Send exactly one file.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            if (in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->tooLarge();
            }
            if (!$file->isValid()) {
                return $this->validationError([['propertyPath' => 'file', 'message' => 'The upload did not complete. Send the file again.']]);
            }

            $size = (int) $file->getSize();
            if ($size > AttachmentPolicy::MAX_BYTES) {
                return $this->tooLarge();
            }
            if (0 === $size) {
                return $this->validationError([['propertyPath' => 'file', 'message' => 'The file is empty.']]);
            }

            $filename = AttachmentPolicy::sanitizeFilename($file->getClientOriginalName());
            $mimeType = AttachmentPolicy::mimeTypeFor($filename, $file->getPathname());
            if (null === $mimeType) {
                return $this->error('Only PNG, JPG, PDF, TXT and CSV files are allowed, and the content must match the extension.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }

            $retryAfter = $this->throttle->attempt('attachment_upload', (string) $user->getId(), self::UPLOADS_PER_MINUTE, 60);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $storageKey = null;
            try {
                $attachment = $this->entityManager->wrapInTransaction(function () use ($note, $user, $file, $filename, $mimeType, $size, &$storageKey): ?NoteAttachment {
                    // Serializes uploads to one note, so concurrent ones cannot go past the limit together.
                    $this->entityManager->lock($note, LockMode::PESSIMISTIC_WRITE);
                    if ($this->attachments->countByNote($note) >= AttachmentPolicy::MAX_PER_NOTE) {
                        return null;
                    }

                    $storageKey = $this->storage->store($file->getPathname());
                    $attachment = new NoteAttachment($note, $user->getId(), $filename, $storageKey, $mimeType, $size, (string) hash_file('sha256', $file->getPathname()));
                    $this->entityManager->persist($attachment);
                    $this->audit->record(AuditEvent::ATTACHMENT_UPLOADED, $user, (string) $note->getId(), metadata: ['mime_type' => $mimeType, 'size' => $size], subjectId: $attachment->getId());

                    return $attachment;
                });
            } catch (\Throwable $e) {
                // The record was not saved: the file must not stay behind.
                if (null !== $storageKey) {
                    $this->storage->delete([$storageKey]);
                }
                throw $e;
            }

            if (null === $attachment) {
                return $this->validationError([['propertyPath' => 'file', 'message' => sprintf('A note can have at most %d attachments.', AttachmentPolicy::MAX_PER_NOTE)]]);
            }

            return $this->json($this->attachmentData($attachment), Response::HTTP_CREATED, ['Location' => $this->downloadUrl($attachment)]);
        });
    }

    #[Route('/api/notes/{id}/attachments', name: 'api_note_attachments_list', methods: ['GET'])]
    #[OA\Get(summary: 'List the attachments of a note.', description: 'Anyone who can see the note. Ordered by upload time, then by name.')]
    #[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Attachments (at most 5).', content: new OA\JsonContent(
        required: ['items', 'total', 'max'],
        properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/NoteAttachment')),
            new OA\Property(property: 'total', type: 'integer', example: 1),
            new OA\Property(property: 'max', description: 'Most attachments a note can have.', type: 'integer', example: 5),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
    public function list(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $note = $this->findNote($id, $user);
            if (!$note instanceof Note) {
                return $note;
            }

            $items = array_map($this->attachmentData(...), $this->attachments->findByNote($note));

            return $this->json(['items' => $items, 'total' => count($items), 'max' => AttachmentPolicy::MAX_PER_NOTE]);
        });
    }

    #[Route('/api/attachments/{id}/download', name: 'api_attachment_download', methods: ['GET'])]
    #[OA\Get(summary: 'Download an attachment.', description: 'Anyone who can currently see the note. Always served as a download ("Content-Disposition: attachment") with the stored type and "X-Content-Type-Options: nosniff".')]
    #[OA\Parameter(name: 'id', description: 'Attachment ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The file, byte for byte as uploaded.', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary')))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Attachment is missing, or its note is not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Attachment not found.']))]
    public function download(string $id): Response
    {
        $user = $this->getUser();
        $attachment = $user instanceof User ? $this->findAttachment($id, $user) : null;
        if (null === $attachment || !$this->storage->exists($attachment->getStorageKey())) {
            return $this->error($user instanceof User ? 'Attachment not found.' : 'Unauthorized.', $user instanceof User ? Response::HTTP_NOT_FOUND : Response::HTTP_UNAUTHORIZED);
        }

        $response = new BinaryFileResponse($this->storage->path($attachment->getStorageKey()));
        $response->headers->set('Content-Type', $attachment->getMimeType());
        // Never rendered in the app's origin: always a download, never sniffed, no scripts.
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $attachment->getFilename(),
            self::asciiFallback($attachment->getFilename()),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/api/attachments/{id}', name: 'api_attachment_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Delete an attachment.', description: 'Owner and editors of the note.')]
    #[OA\Parameter(name: 'id', description: 'Attachment ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'Attachment deleted.')]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'The caller can only view the note.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Your role does not allow changing the attachments of this note.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Attachment is missing, or its note is not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Attachment not found.']))]
    public function delete(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $attachment = $this->findAttachment($id, $user);
            if (null === $attachment) {
                return $this->error('Attachment not found.', Response::HTTP_NOT_FOUND);
            }
            if (!$this->access->canEdit($attachment->getNote(), $user)) {
                return $this->error('Your role does not allow changing the attachments of this note.', Response::HTTP_FORBIDDEN);
            }

            $storageKey = $attachment->getStorageKey();
            $this->audit->record(AuditEvent::ATTACHMENT_DELETED, $user, (string) $attachment->getNote()->getId(), subjectId: $attachment->getId());
            $this->entityManager->remove($attachment);
            $this->entityManager->flush();
            $this->storage->delete([$storageKey]);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * @return Note|JsonResponse the note, or the 404 to answer with
     */
    private function findNote(string $id, User $user): Note|JsonResponse
    {
        $note = Uuid::isValid($id) ? $this->notes->find($id) : null;
        if (null === $note || !$this->access->canView($note, $user)) {
            return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
        }

        return $note;
    }

    /**
     * An attachment of a note the caller cannot see does not exist for them.
     */
    private function findAttachment(string $id, User $user): ?NoteAttachment
    {
        $attachment = Uuid::isValid($id) ? $this->attachments->find($id) : null;

        return null !== $attachment && $this->access->canView($attachment->getNote(), $user) ? $attachment : null;
    }

    private function tooLarge(): JsonResponse
    {
        return $this->error('File is larger than 10 MiB.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    private function downloadUrl(NoteAttachment $attachment): string
    {
        return sprintf('/api/attachments/%s/download', $attachment->getId());
    }

    private static function asciiFallback(string $filename): string
    {
        $fallback = (string) preg_replace('/[^\x20-\x7E]/', '_', $filename);

        return str_replace(['%', '/', '\\'], '_', $fallback);
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentData(NoteAttachment $attachment): array
    {
        return [
            'id' => $attachment->getId(),
            'note_id' => (string) $attachment->getNote()->getId(),
            'filename' => $attachment->getFilename(),
            'mime_type' => $attachment->getMimeType(),
            'size' => $attachment->getSize(),
            'sha256' => $attachment->getSha256(),
            'uploaded_by' => $attachment->getUploadedBy(),
            'created_at' => $attachment->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'download_url' => $this->downloadUrl($attachment),
        ];
    }
}
