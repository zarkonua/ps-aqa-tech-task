<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiPlatform\NoteAccessExtension;
use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\NoteExchangeFormat;
use App\Service\NoteImporter;
use App\Service\Throttle;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Import and Export')]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'Too many imports or exports in a minute.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class NoteExchangeController extends ApiController
{
    public const IMPORTS_PER_MINUTE = 20;
    public const EXPORTS_PER_MINUTE = 20;
    public const MAX_EXPORT_NOTES = 5000;
    public const PREVIEW_ROWS = 20;

    private const IMPORT_DESCRIPTION = 'Multipart form with the file in "file" (.csv or .json, up to 5 MiB and 1000 rows) and the options "mode" (atomic: nothing is imported unless every row is valid, the default; partial: valid rows are imported and the others reported) and "duplicates" (what to do with a row whose external_id I already use: reject, the default; skip; upsert). Imported notes are always mine; tags and categories named in the file are created if I do not have them.';

    public function __construct(
        private readonly NoteImporter $importer,
        private readonly Throttle $throttle,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/imports/notes/validate', name: 'api_notes_import_validate', methods: ['POST'])]
    #[OA\Post(summary: 'Check an import file without importing it.', description: self::IMPORT_DESCRIPTION.' Nothing is changed by this route.')]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/NoteImportRequest')))]
    #[OA\Response(response: Response::HTTP_OK, description: 'What importing would do, row by row; "errors" lists what is wrong.', content: new OA\JsonContent(ref: '#/components/schemas/NoteImportPreview'))]
    #[OA\Response(response: Response::HTTP_REQUEST_ENTITY_TOO_LARGE, description: 'File larger than 5 MiB.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'The file is larger than 5 MiB.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'The file cannot be read at all (no header row, unknown column, not JSON, more than 1000 rows) or an option is invalid.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'file', 'message' => 'The header row must contain the columns "title" and "content"; missing: content.']]]))]
    public function validate(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $input = $this->read($request, $user);

            return $input instanceof JsonResponse ? $input : $this->json($this->summary($input, null));
        });
    }

    #[Route('/api/imports/notes', name: 'api_notes_import', methods: ['POST'])]
    #[OA\Post(summary: 'Import notes from a CSV or JSON file.', description: self::IMPORT_DESCRIPTION.' In atomic mode a file with any invalid row is refused with 422 and nothing changes.')]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/NoteImportRequest')))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Imported. In partial mode "errors" lists the rows that were left out.', content: new OA\JsonContent(ref: '#/components/schemas/NoteImportResult'))]
    #[OA\Response(response: Response::HTTP_REQUEST_ENTITY_TOO_LARGE, description: 'File larger than 5 MiB.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'The file is larger than 5 MiB.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'The file cannot be read at all, an option is invalid, or (atomic mode) at least one row is invalid: the body then has the same shape as the validation route, with "imported": false.', content: new OA\JsonContent(ref: '#/components/schemas/NoteImportPreview'))]
    public function import(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $input = $this->read($request, $user);
            if ($input instanceof JsonResponse) {
                return $input;
            }

            if ('atomic' === $input['mode'] && [] !== $input['errors']) {
                return $this->json(['error' => 'The file has invalid rows; nothing was imported.'] + $this->summary($input, null) + ['imported' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $results = $this->importer->apply($user, $input['rows']);
            $counts = array_count_values(array_column($results, 'action')) + ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $this->audit->record(AuditEvent::NOTES_IMPORTED, $user, null, metadata: [
                'format' => $input['format'],
                'mode' => $input['mode'],
                'duplicates' => $input['duplicates'],
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'failed' => count(array_unique(array_column($input['errors'], 'line'))),
            ]);
            $this->entityManager->flush();

            return $this->json($this->summary($input, $results) + ['imported' => true]);
        });
    }

    #[Route('/api/exports/notes', name: 'api_notes_export', methods: ['GET'])]
    #[OA\Get(summary: 'Export my notes as CSV or JSON.', description: 'By default only notes I own. With scope=all or scope=shared, notes that are shared with me right now are included; notes I cannot see are never exported. CSV is UTF-8 with a byte order mark, every field quoted, and values that a spreadsheet would run as a formula are prefixed with an apostrophe. The JSON document carries "schema" and "schema_version". At most 5000 notes.')]
    #[OA\Parameter(name: 'format', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['csv', 'json']))]
    #[OA\Parameter(name: 'scope', in: 'query', schema: new OA\Schema(type: 'string', default: 'owned', enum: ['owned', 'shared', 'all']))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The export, as a file to save.', content: [
        new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', example: "\"external_id\",\"title\",\"content\",\"tags\",\"category\",\"created_at\",\"updated_at\"\r\n\"a-1\",\"Plan\",\"Text\",\"work;idea\",\"Projects\",\"2026-10-11T09:30:00+00:00\",\"2026-10-12T10:00:00+00:00\"\r\n")),
        new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(ref: '#/components/schemas/NoteExport')),
    ])]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Missing or unknown format or scope, or more than 5000 notes.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function export(Request $request): Response
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $query = $request->query->all();
            $violations = [];
            $format = $query['format'] ?? null;
            if (!in_array($format, ['csv', 'json'], true)) {
                $violations[] = ['propertyPath' => 'format', 'message' => 'format must be "csv" or "json".'];
            }
            $scope = $query['scope'] ?? NoteAccessExtension::SCOPE_OWNED;
            if (!in_array($scope, NoteAccessExtension::SCOPES, true)) {
                $violations[] = ['propertyPath' => 'scope', 'message' => 'scope must be one of: owned, shared, all.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $retryAfter = $this->throttle->attempt('notes_export', (string) $user->getId(), self::EXPORTS_PER_MINUTE, 60);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $owned = 'n.owner = :user';
            $shared = 'EXISTS (SELECT 1 FROM '.NoteShare::class.' s WHERE s.note = n AND s.user = :user)';
            $notes = $this->entityManager->createQueryBuilder()
                ->select('n')
                ->from(Note::class, 'n')
                ->where(match ($scope) {
                    NoteAccessExtension::SCOPE_SHARED => $shared,
                    NoteAccessExtension::SCOPE_ALL => "($owned OR $shared)",
                    default => $owned,
                })
                ->setParameter('user', $user)
                ->orderBy('n.createdAt', 'ASC')
                ->addOrderBy('n.id', 'ASC')
                ->setMaxResults(self::MAX_EXPORT_NOTES + 1)
                ->getQuery()
                ->getResult();
            if (count($notes) > self::MAX_EXPORT_NOTES) {
                return $this->validationError([['propertyPath' => 'scope', 'message' => sprintf('More than %d notes match; an export holds at most that many.', self::MAX_EXPORT_NOTES)]]);
            }

            $utc = new \DateTimeZone('UTC');
            $rows = array_map(static function (Note $note) use ($utc): array {
                $tags = array_map(static fn (Tag $tag): string => $tag->getName(), $note->getTags()->toArray());
                sort($tags);

                return [
                    'external_id' => $note->getExternalId(),
                    'title' => $note->getTitle(),
                    'content' => $note->getContent(),
                    'tags' => $tags,
                    'category' => $note->getCategory()?->getName(),
                    'created_at' => $note->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
                    'updated_at' => $note->getUpdatedAt()->setTimezone($utc)->format(\DATE_ATOM),
                ];
            }, $notes);

            $this->audit->record(AuditEvent::NOTES_EXPORTED, $user, null, metadata: ['format' => $format, 'scope' => $scope, 'count' => count($rows)]);
            $this->entityManager->flush();

            $now = new \DateTimeImmutable();
            $response = new JsonResponse();
            $response->setContent('csv' === $format ? NoteExchangeFormat::toCsv($rows) : NoteExchangeFormat::toJson($rows, $now));
            $response->headers->set('Content-Type', 'csv' === $format ? 'text/csv; charset=utf-8' : 'application/json');
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('notes-%s.%s', $now->setTimezone($utc)->format('Ymd-His'), $format)));
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Total-Count', (string) count($rows));

            return $response;
        });
    }

    /**
     * Reads and checks the upload and its options, and plans the import.
     *
     * @return array<string, mixed>|JsonResponse the plan, or the refusal
     */
    private function read(Request $request, User $user): array|JsonResponse
    {
        $violations = [];
        foreach (array_diff([...$request->files->keys(), ...$request->request->keys()], ['file', 'mode', 'duplicates', 'format']) as $field) {
            $violations[] = ['propertyPath' => mb_substr((string) $field, 0, 64), 'message' => 'Unexpected field.'];
        }

        $mode = $request->request->all()['mode'] ?? 'atomic';
        if (!in_array($mode, NoteImporter::MODES, true)) {
            $violations[] = ['propertyPath' => 'mode', 'message' => 'mode must be "atomic" or "partial".'];
        }
        $duplicates = $request->request->all()['duplicates'] ?? 'reject';
        if (!in_array($duplicates, NoteImporter::DUPLICATE_POLICIES, true)) {
            $violations[] = ['propertyPath' => 'duplicates', 'message' => 'duplicates must be "reject", "skip" or "upsert".'];
        }

        $file = $request->files->get('file');
        if (null === $file && (int) $request->headers->get('Content-Length', '0') > NoteImporter::MAX_BYTES && 0 === $request->request->count()) {
            return $this->error('The file is larger than 5 MiB.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if (!$file instanceof UploadedFile) {
            $violations[] = ['propertyPath' => 'file', 'message' => null === $file ? 'A file is required in the multipart field "file".' : 'Send exactly one file.'];
        } elseif (in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) || ($file->isValid() && $file->getSize() > NoteImporter::MAX_BYTES)) {
            return $this->error('The file is larger than 5 MiB.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        } elseif (!$file->isValid()) {
            $violations[] = ['propertyPath' => 'file', 'message' => 'The upload did not complete. Send the file again.'];
        }

        $format = $request->request->all()['format'] ?? null;
        if (null === $format && $file instanceof UploadedFile) {
            $format = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        }
        if ([] === $violations && !in_array($format, ['csv', 'json'], true)) {
            $violations[] = ['propertyPath' => 'format', 'message' => 'The file must be .csv or .json, or "format" must say which it is.'];
        }
        if ([] !== $violations) {
            return $this->validationError($violations);
        }

        $retryAfter = $this->throttle->attempt('notes_import', (string) $user->getId(), self::IMPORTS_PER_MINUTE, 60);
        if (null !== $retryAfter) {
            return $this->tooManyRequests($retryAfter);
        }

        try {
            $parsed = 'csv' === $format
                ? NoteExchangeFormat::parseCsv((string) file_get_contents($file->getPathname()))
                : NoteExchangeFormat::parseJson((string) file_get_contents($file->getPathname()));
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([['propertyPath' => 'file', 'message' => $e->getMessage()]]);
        }

        $totalRows = count($parsed['rows']) + count(array_unique(array_column($parsed['errors'], 'line')));
        if ($totalRows > NoteImporter::MAX_ROWS) {
            return $this->validationError([['propertyPath' => 'file', 'message' => sprintf('The file has %d rows; at most %d can be imported at once.', $totalRows, NoteImporter::MAX_ROWS)]]);
        }
        if (0 === $totalRows) {
            return $this->validationError([['propertyPath' => 'file', 'message' => 'The file has no rows to import.']]);
        }

        return $this->importer->plan($user, $parsed['rows'], $parsed['errors'], $duplicates) + ['format' => $format, 'mode' => $mode, 'duplicates' => $duplicates, 'total_rows' => $totalRows];
    }

    /**
     * @param array<string, mixed>                                         $input
     * @param list<array{line: int, action: string, id: string|null}>|null $results what was done; null when nothing was
     *
     * @return array<string, mixed>
     */
    private function summary(array $input, ?array $results): array
    {
        $actions = array_count_values(array_column($input['rows'], 'action')) + ['create' => 0, 'update' => 0, 'skip' => 0];
        $invalid = count(array_unique(array_column($input['errors'], 'line')));

        $summary = [
            'format' => $input['format'],
            'mode' => $input['mode'],
            'duplicates' => $input['duplicates'],
            'total_rows' => $input['total_rows'],
            'valid_rows' => count($input['rows']),
            'invalid_rows' => $invalid,
            'create' => $actions['create'],
            'update' => $actions['update'],
            'skip' => $actions['skip'],
            // Would this file be accepted as it is, in this mode?
            'importable' => 0 === $invalid || ('partial' === $input['mode'] && [] !== $input['rows']),
            'errors' => array_slice($input['errors'], 0, 200),
        ];

        if (null === $results) {
            return $summary + ['preview' => array_map(static fn (array $row): array => [
                'line' => $row['line'],
                'action' => $row['action'],
                'external_id' => $row['external_id'],
                'title' => $row['title'],
                'content_length' => mb_strlen($row['content']),
                'tags' => $row['tags'],
                'category' => $row['category'],
            ], array_slice($input['rows'], 0, self::PREVIEW_ROWS))];
        }

        return $summary + ['notes' => $results];
    }
}
