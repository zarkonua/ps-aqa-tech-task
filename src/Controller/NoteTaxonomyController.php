<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditEvent;
use App\Entity\Category;
use App\Entity\Note;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\TaxonomyRepository;
use App\Security\NoteAccess;
use App\Service\AuditLogger;
use App\Util\Uuid;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tags and category of one note. They always come from the note owner's taxonomy:
 * the owner and editors may pick among them, viewers may not change anything.
 */
#[OA\Tag(name: 'Note Tags and Category')]
#[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(
    response: Response::HTTP_FORBIDDEN,
    description: 'The caller can only view the note.',
    content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Your role does not allow changing the tags or category of this note.']),
)]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class NoteTaxonomyController extends ApiController
{
    public const MAX_TAGS_PER_NOTE = 10;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly TaxonomyRepository $taxonomy,
        private readonly NoteAccess $access,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notes/{id}/taxonomy', name: 'api_note_taxonomy', methods: ['GET'])]
    #[OA\Get(summary: 'List the tags and categories that can be put on a note.', description: 'Owner and editors. Returns the note owner\'s tags and categories, ordered by name.')]
    #[OA\Response(response: Response::HTTP_OK, description: 'Available tags and categories.', content: new OA\JsonContent(
        required: ['tags', 'categories'],
        properties: [
            new OA\Property(property: 'tags', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaxonomyItem')),
            new OA\Property(property: 'categories', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaxonomyItem')),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
    public function available(string $id): JsonResponse
    {
        return $this->handle($id, function (Note $note): JsonResponse {
            $owner = $note->getOwner();

            return $this->json([
                'tags' => array_map(TaxonomyView::full(...), $this->taxonomy->findPage(Tag::class, $owner, null, 1, TaxonomyController::MAX_ITEMS_PER_USER)),
                'categories' => array_map(TaxonomyView::full(...), $this->taxonomy->findPage(Category::class, $owner, null, 1, TaxonomyController::MAX_ITEMS_PER_USER)),
            ]);
        });
    }

    #[Route('/api/notes/{id}/tags', name: 'api_note_tags_replace', methods: ['PUT'])]
    #[OA\Put(summary: 'Replace the tags of a note.', description: 'Owner and editors. The list replaces the current tags; send an empty list to remove all. Tags must belong to the note owner.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['tag_ids'],
        properties: [
            new OA\Property(property: 'tag_ids', type: 'array', maxItems: 10, uniqueItems: true, items: new OA\Items(type: 'string', format: 'uuid'), example: ['0b0f6f0e-3c5e-4e63-9f0a-6f2f0d0c1a11']),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Tags replaced.', content: new OA\JsonContent(ref: '#/components/schemas/NoteTaxonomy'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible, or a tag is missing or not one of the note owner\'s tags.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Tag not found.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed, e.g. more than 10 tags.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'tag_ids', 'message' => 'A note can have at most 10 tags.']]]))]
    public function replaceTags(string $id, Request $request): JsonResponse
    {
        return $this->handle($id, function (Note $note, User $actor) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['tag_ids']);
            $tagIds = $this->validatedTagIds($payload, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $tags = $this->taxonomy->findOwnedByIds(Tag::class, $tagIds, $note->getOwner());
            if (count($tags) !== count($tagIds)) {
                return $this->error('Tag not found.', Response::HTTP_NOT_FOUND);
            }

            $this->entityManager->wrapInTransaction(function () use ($note, $tags, $tagIds, $actor): void {
                // Serializes concurrent replacements, so two of them cannot add up to more than the limit.
                $this->entityManager->lock($note, LockMode::PESSIMISTIC_WRITE);

                $before = array_map(static fn (Tag $tag): string => $tag->getId(), $note->getTags()->toArray());
                $note->replaceTags($tags);

                $added = array_values(array_diff($tagIds, $before));
                $removed = array_values(array_diff($before, $tagIds));
                if ([] !== $added || [] !== $removed) {
                    $this->audit->record(AuditEvent::NOTE_TAGS_CHANGED, $actor, (string) $note->getId(), metadata: ['added' => $added, 'removed' => $removed]);
                }
            });

            return $this->json(TaxonomyView::ofNote($note));
        });
    }

    #[Route('/api/notes/{id}/category', name: 'api_note_category_set', methods: ['PUT'])]
    #[OA\Put(summary: 'Set or clear the category of a note.', description: 'Owner and editors. A note has at most one category; send null to clear it. The category must belong to the note owner.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['category_id'],
        properties: [
            new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true, example: '5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10'),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Category set.', content: new OA\JsonContent(ref: '#/components/schemas/NoteTaxonomy'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible, or the category is missing or not one of the note owner\'s categories.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Category not found.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function setCategory(string $id, Request $request): JsonResponse
    {
        return $this->handle($id, function (Note $note, User $actor) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['category_id']);
            $categoryId = $payload['category_id'] ?? null;
            if (!array_key_exists('category_id', $payload)) {
                $violations[] = ['propertyPath' => 'category_id', 'message' => 'Category ID is required; send null to clear the category.'];
            } elseif (null !== $categoryId && (!is_string($categoryId) || !Uuid::isValid($categoryId))) {
                $violations[] = ['propertyPath' => 'category_id', 'message' => 'Category ID must be a category ID or null.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $category = null;
            if (null !== $categoryId) {
                $category = $this->taxonomy->findOwned(Category::class, strtolower($categoryId), $note->getOwner());
                if (null === $category) {
                    return $this->error('Category not found.', Response::HTTP_NOT_FOUND);
                }
            }

            $previousId = $note->getCategory()?->getId();
            if ($previousId !== $category?->getId()) {
                $note->setCategory($category);
                $this->audit->record(AuditEvent::NOTE_CATEGORY_CHANGED, $actor, (string) $note->getId(), metadata: ['from' => $previousId, 'to' => $category?->getId()]);
                $this->entityManager->flush();
            }

            return $this->json(TaxonomyView::ofNote($note));
        });
    }

    /**
     * A note the caller cannot see is reported as missing; one they can only view is forbidden.
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

            if (!$this->access->canEdit($note, $user)) {
                return $this->error('Your role does not allow changing the tags or category of this note.', Response::HTTP_FORBIDDEN);
            }

            return $action($note, $user);
        });
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     *
     * @return list<string>
     */
    private function validatedTagIds(array $payload, array &$violations): array
    {
        $tagIds = $payload['tag_ids'] ?? null;
        if (!is_array($tagIds) || !array_is_list($tagIds)) {
            $violations[] = ['propertyPath' => 'tag_ids', 'message' => array_key_exists('tag_ids', $payload) ? 'Tag IDs must be a list.' : 'Tag IDs are required; send an empty list to remove all tags.'];

            return [];
        }

        if (count($tagIds) > self::MAX_TAGS_PER_NOTE) {
            $violations[] = ['propertyPath' => 'tag_ids', 'message' => sprintf('A note can have at most %d tags.', self::MAX_TAGS_PER_NOTE)];

            return [];
        }

        $valid = [];
        foreach ($tagIds as $index => $tagId) {
            if (!is_string($tagId) || !Uuid::isValid($tagId)) {
                $violations[] = ['propertyPath' => sprintf('tag_ids[%d]', $index), 'message' => 'Tag ID must be a tag ID.'];
            } elseif (in_array(strtolower($tagId), $valid, true)) {
                $violations[] = ['propertyPath' => sprintf('tag_ids[%d]', $index), 'message' => 'Tag ID is listed more than once.'];
            } else {
                $valid[] = strtolower($tagId);
            }
        }

        return $valid;
    }
}
