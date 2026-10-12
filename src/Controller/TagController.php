<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Note;
use App\Entity\Tag;
use App\Entity\TaxonomyItem;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Tags')]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class TagController extends TaxonomyController
{
    #[Route('/api/tags', name: 'api_tags_list', methods: ['GET'])]
    #[OA\Get(summary: 'List my tags.', description: 'Only the caller\'s own tags, ordered by name.')]
    #[OA\Parameter(name: 'q', description: 'Case-insensitive part of the name.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 30))]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'itemsPerPage', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The caller\'s tags.', content: new OA\JsonContent(ref: '#/components/schemas/TaxonomyItemList'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Invalid query parameters.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function list(Request $request): JsonResponse
    {
        return $this->listItems($request);
    }

    #[Route('/api/tags', name: 'api_tags_create', methods: ['POST'])]
    #[OA\Post(summary: 'Create a tag.', description: 'The name is trimmed, inner whitespace is collapsed, and it must be unique among the caller\'s tags ignoring case. At most 100 tags per user.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaxonomyItemCreate'))]
    #[OA\Response(response: Response::HTTP_CREATED, description: 'Tag created.', content: new OA\JsonContent(ref: '#/components/schemas/TaxonomyItem'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'A tag with this name already exists (ignoring case), or the limit is reached.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'A tag with this name already exists.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'name', 'message' => 'Name is required.']]]))]
    public function create(Request $request): JsonResponse
    {
        return $this->createItem($request);
    }

    #[Route('/api/tags/{id}', name: 'api_tags_update', methods: ['PATCH'])]
    #[OA\Patch(summary: 'Rename or recolor a tag.', description: 'Send "name", "color" or both. Send the last seen "version" to detect concurrent changes.')]
    #[OA\Parameter(name: 'id', description: 'Tag ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaxonomyItemUpdate'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Tag updated (or nothing to change).', content: new OA\JsonContent(ref: '#/components/schemas/TaxonomyItem'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Tag is missing or belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Tag not found.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'Name already used, or the tag was changed by another request.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'A tag with this name already exists.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->updateItem($id, $request);
    }

    #[Route('/api/tags/{id}', name: 'api_tags_delete', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Delete a tag.', description: 'Notes that used it are kept and only lose the tag.')]
    #[OA\Parameter(name: 'id', description: 'Tag ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'Tag deleted.')]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Tag is missing or belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Tag not found.']))]
    public function delete(string $id): JsonResponse
    {
        return $this->deleteItem($id);
    }

    protected function itemClass(): string
    {
        return Tag::class;
    }

    protected function label(): string
    {
        return 'Tag';
    }

    protected function countNotesUsing(TaxonomyItem $item): int
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(n.id) FROM '.Note::class.' n WHERE :item MEMBER OF n.tags')
            ->setParameter('item', $item)
            ->getSingleScalarResult();
    }
}
