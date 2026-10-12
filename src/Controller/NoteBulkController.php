<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Note;
use App\Entity\Tag;
use App\Entity\User;
use App\EventSubscriber\CorrelationIdSubscriber;
use App\Repository\NoteRepository;
use App\Repository\TaxonomyRepository;
use App\Security\NoteAccess;
use App\Service\NoteMutator;
use App\Service\Throttle;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Note Bulk Operations')]
final class NoteBulkController extends ApiController
{
    public const MAX_IDS = 100;
    public const REQUESTS_PER_MINUTE = 20;
    public const OPERATIONS = ['setCategory', 'addTags', 'removeTags', 'delete'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly TaxonomyRepository $taxonomy,
        private readonly NoteAccess $access,
        private readonly NoteMutator $mutator,
        private readonly Throttle $throttle,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notes/bulk', name: 'api_notes_bulk', methods: ['POST'], priority: 10)]
    #[OA\Post(
        summary: 'Apply one operation to many notes.',
        description: 'Each note is checked and changed on its own: the ones that cannot be changed are reported in "failed" and do not stop the others. Repeated IDs are processed once. Tags and categories must belong to the owner of each note. Deleting is for owners; the other operations are for owners and editors. Limit: 20 requests per minute.',
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['operation', 'ids'],
        properties: [
            new OA\Property(property: 'operation', type: 'string', enum: self::OPERATIONS, example: 'addTags'),
            new OA\Property(property: 'ids', description: 'Note IDs.', type: 'array', maxItems: 100, minItems: 1, items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(
                property: 'payload',
                description: 'setCategory: {"category_id": "<id>" or null}. addTags / removeTags: {"tag_ids": [1 to 10 IDs]}. delete: omitted or {}.',
                type: 'object',
                example: ['tag_ids' => ['0b0f6f0e-3c5e-4e63-9f0a-6f2f0d0c1a11']],
            ),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Processed; look at "failed" for the notes that were not changed.', content: new OA\JsonContent(
        required: ['operation', 'total', 'succeeded', 'failed', 'correlation_id'],
        properties: [
            new OA\Property(property: 'operation', type: 'string', example: 'addTags'),
            new OA\Property(property: 'total', description: 'Number of distinct note IDs processed.', type: 'integer', example: 3),
            new OA\Property(property: 'succeeded', description: 'IDs of the notes that are now in the requested state.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'failed', type: 'array', items: new OA\Items(
                required: ['id', 'code', 'message'],
                properties: [
                    new OA\Property(property: 'id', type: 'string', example: '5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10'),
                    new OA\Property(property: 'code', type: 'string', enum: ['not_found', 'forbidden', 'tag_not_found', 'category_not_found', 'tag_limit', 'error'], example: 'forbidden'),
                    new OA\Property(property: 'message', type: 'string', example: 'Your role does not allow changing this note.'),
                ],
                type: 'object',
            )),
            new OA\Property(property: 'correlation_id', type: 'string', example: 'c2a4b2c4-6f0e-4b53-9f0a-2d6b3a9c7e10'),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Unsupported operation, more than 100 IDs, or a malformed payload. Nothing is processed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'ids', 'message' => 'At most 100 note IDs can be processed at once.']]]))]
    #[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'More than 20 bulk requests in a minute.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
    #[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
    public function bulk(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $body = $this->objectPayload($request);
            $violations = $this->unexpectedFields($body, ['operation', 'ids', 'payload']);
            $operation = $this->validatedOperation($body, $violations);
            $ids = $this->validatedIds($body, 'ids', self::MAX_IDS, 'note', $violations);
            $payload = null === $operation ? [] : $this->validatedPayload($operation, $body, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $retryAfter = $this->throttle->attempt('notes_bulk', (string) $user->getId(), self::REQUESTS_PER_MINUTE, 60);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $succeeded = [];
            $failed = [];
            foreach ($ids as $id) {
                try {
                    $failure = $this->entityManager->isOpen() ? $this->apply($operation, $id, $payload, $user) : ['error', 'Could not process this note.'];
                } catch (\Throwable) {
                    $failure = ['error', 'Could not process this note.'];
                }

                if (null === $failure) {
                    $succeeded[] = $id;
                } else {
                    $failed[] = ['id' => $id, 'code' => $failure[0], 'message' => $failure[1]];
                }
            }

            return $this->json([
                'operation' => $operation,
                'total' => count($ids),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'correlation_id' => (string) $request->attributes->get(CorrelationIdSubscriber::ATTRIBUTE),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{string, string}|null code and message of the failure, or null on success
     */
    private function apply(string $operation, string $id, array $payload, User $actor): ?array
    {
        $note = Uuid::isValid($id) ? $this->notes->find($id) : null;
        // A note the caller cannot see is indistinguishable from one that does not exist.
        if (!$note instanceof Note || !$this->access->canView($note, $actor)) {
            return ['not_found', 'Note not found.'];
        }

        if ('delete' === $operation) {
            if (!$this->access->isOwner($note, $actor)) {
                return ['forbidden', 'Only the note owner can delete this note.'];
            }
            $this->mutator->delete($note, $actor);

            return null;
        }

        if (!$this->access->canEdit($note, $actor)) {
            return ['forbidden', 'Your role does not allow changing this note.'];
        }

        if ('setCategory' === $operation) {
            $category = null;
            if (null !== $payload['category_id']) {
                $category = $this->taxonomy->findOwned(Category::class, $payload['category_id'], $note->getOwner());
                if (null === $category) {
                    return ['category_not_found', "The category is not one of the note owner's categories."];
                }
            }
            $this->mutator->setCategory($note, $actor, $category);

            return null;
        }

        $tags = $this->taxonomy->findOwnedByIds(Tag::class, $payload['tag_ids'], $note->getOwner());
        if (count($tags) !== count($payload['tag_ids'])) {
            return ['tag_not_found', "A tag is not one of the note owner's tags."];
        }

        $tagIds = $payload['tag_ids'];
        $withinLimit = $this->mutator->changeTags(
            $note,
            $actor,
            'addTags' === $operation
                ? static fn (array $current): array => array_values(array_merge(
                    $current,
                    array_udiff($tags, $current, static fn (Tag $a, Tag $b): int => strcmp($a->getId(), $b->getId())),
                ))
                : static fn (array $current): array => array_values(array_filter($current, static fn (Tag $tag): bool => !in_array($tag->getId(), $tagIds, true))),
        );

        return $withinLimit ? null : ['tag_limit', sprintf('A note can have at most %d tags.', NoteMutator::MAX_TAGS_PER_NOTE)];
    }

    /**
     * @param array<string, mixed>                               $body
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function validatedOperation(array $body, array &$violations): ?string
    {
        $operation = $body['operation'] ?? null;
        if (is_string($operation) && in_array($operation, self::OPERATIONS, true)) {
            return $operation;
        }

        $violations[] = [
            'propertyPath' => 'operation',
            'message' => null === $operation ? 'Operation is required.' : 'Unsupported operation. Use one of: '.implode(', ', self::OPERATIONS).'.',
        ];

        return null;
    }

    /**
     * A list of distinct ID strings; repeated IDs are dropped, so nothing is processed twice.
     * IDs that are not well-formed are kept: they are reported per item as not found.
     *
     * @param array<string, mixed>                               $source
     * @param list<array{propertyPath: string, message: string}> $violations
     *
     * @return list<string>
     */
    private function validatedIds(array $source, string $field, int $max, string $what, array &$violations, string $path = ''): array
    {
        $ids = $source[$field] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || [] === $ids) {
            $violations[] = ['propertyPath' => $path.$field, 'message' => sprintf('A non-empty list of %s IDs is required.', $what)];

            return [];
        }

        if (count($ids) > $max) {
            $violations[] = ['propertyPath' => $path.$field, 'message' => sprintf('At most %d %s IDs can be processed at once.', $max, $what)];

            return [];
        }

        $unique = [];
        foreach ($ids as $index => $id) {
            if (!is_string($id) || '' === $id || strlen($id) > 64) {
                $violations[] = ['propertyPath' => sprintf('%s%s[%d]', $path, $field, $index), 'message' => sprintf('Each %s ID must be a string.', $what)];
                continue;
            }
            $unique[strtolower($id)] = true;
        }

        return array_map('strval', array_keys($unique));
    }

    /**
     * @param array<string, mixed>                               $body
     * @param list<array{propertyPath: string, message: string}> $violations
     *
     * @return array<string, mixed>
     */
    private function validatedPayload(string $operation, array $body, array &$violations): array
    {
        $payload = $body['payload'] ?? [];
        if (!is_array($payload) || ([] !== $payload && array_is_list($payload))) {
            $violations[] = ['propertyPath' => 'payload', 'message' => 'Payload must be an object.'];

            return [];
        }

        if ('delete' === $operation) {
            foreach ($this->unexpectedFields($payload, []) as $violation) {
                $violations[] = ['propertyPath' => 'payload.'.$violation['propertyPath'], 'message' => $violation['message']];
            }

            return [];
        }

        if ('setCategory' === $operation) {
            foreach ($this->unexpectedFields($payload, ['category_id']) as $violation) {
                $violations[] = ['propertyPath' => 'payload.'.$violation['propertyPath'], 'message' => $violation['message']];
            }
            $categoryId = $payload['category_id'] ?? null;
            if (!array_key_exists('category_id', $payload)) {
                $violations[] = ['propertyPath' => 'payload.category_id', 'message' => 'Category ID is required; send null to clear the category.'];
            } elseif (null !== $categoryId && (!is_string($categoryId) || !Uuid::isValid($categoryId))) {
                $violations[] = ['propertyPath' => 'payload.category_id', 'message' => 'Category ID must be a category ID or null.'];
            }

            return ['category_id' => is_string($categoryId) ? strtolower($categoryId) : null];
        }

        foreach ($this->unexpectedFields($payload, ['tag_ids']) as $violation) {
            $violations[] = ['propertyPath' => 'payload.'.$violation['propertyPath'], 'message' => $violation['message']];
        }
        $tagIds = $this->validatedIds($payload, 'tag_ids', NoteMutator::MAX_TAGS_PER_NOTE, 'tag', $violations, 'payload.');
        foreach ($tagIds as $tagId) {
            if (!Uuid::isValid($tagId)) {
                $violations[] = ['propertyPath' => 'payload.tag_ids', 'message' => 'Each tag ID must be a tag ID.'];
                break;
            }
        }

        return ['tag_ids' => $tagIds];
    }
}
