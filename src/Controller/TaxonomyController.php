<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TaxonomyItem;
use App\Entity\User;
use App\Repository\TaxonomyRepository;
use App\Service\AuditLogger;
use App\Util\TaxonomyName;
use App\Util\Uuid;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD over the caller's own tags or categories. Items of other users are never
 * found, so nobody but the owner (not even an editor of their notes) can change them.
 */
abstract class TaxonomyController extends ApiController
{
    public const MAX_ITEMS_PER_USER = 100;

    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/D';
    private const DEFAULT_ITEMS_PER_PAGE = 50;
    private const MAX_ITEMS_PER_PAGE = 100;

    public function __construct(
        protected readonly TaxonomyRepository $taxonomy,
        protected readonly AuditLogger $audit,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return class-string<TaxonomyItem>
     */
    abstract protected function itemClass(): string;

    /**
     * "Tag" or "Category", used in messages and audit event names.
     */
    abstract protected function label(): string;

    protected function listItems(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $violations = [];
            $page = $this->validatedQueryInt($request, 'page', 1, 1, PHP_INT_MAX, $violations);
            $itemsPerPage = $this->validatedQueryInt($request, 'itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE, 1, self::MAX_ITEMS_PER_PAGE, $violations);

            $search = $request->query->all()['q'] ?? '';
            if (!is_string($search) || TaxonomyName::length(TaxonomyName::normalize($search)) > TaxonomyName::MAX_LENGTH) {
                $violations[] = ['propertyPath' => 'q', 'message' => sprintf('q must be a string of at most %d characters.', TaxonomyName::MAX_LENGTH)];
                $search = '';
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $search = TaxonomyName::key(TaxonomyName::normalize($search));

            return $this->json([
                'items' => array_map(TaxonomyView::full(...), $this->taxonomy->findPage($this->itemClass(), $user, $search, $page, $itemsPerPage)),
                'total' => $this->taxonomy->count($this->itemClass(), $user, $search),
                'page' => $page,
                'items_per_page' => $itemsPerPage,
            ]);
        });
    }

    protected function createItem(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['name', 'color']);
            $name = $this->validatedName($payload, $violations);
            $color = $this->validatedColor($payload, $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            if (null !== $this->taxonomy->findByNameKey($this->itemClass(), $user, TaxonomyName::key($name))) {
                return $this->duplicateError();
            }

            if ($this->taxonomy->count($this->itemClass(), $user) >= self::MAX_ITEMS_PER_USER) {
                return $this->error(sprintf('%s limit reached (%d).', $this->label(), self::MAX_ITEMS_PER_USER), Response::HTTP_CONFLICT);
            }

            $class = $this->itemClass();
            $item = new $class($user, $name, $color);
            $this->entityManager->persist($item);
            $this->audit->record($this->event('created'), $user, null, subjectId: $item->getId());

            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the same name first.
                return $this->duplicateError();
            }

            return $this->json(TaxonomyView::full($item), Response::HTTP_CREATED);
        });
    }

    protected function updateItem(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $item = $this->findItem($id, $user);
            if (null === $item) {
                return $this->notFound();
            }

            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['name', 'color', 'version']);
            $hasName = array_key_exists('name', $payload);
            $hasColor = array_key_exists('color', $payload);
            $name = $hasName ? $this->validatedName($payload, $violations) : $item->getName();
            $color = $hasColor ? $this->validatedColor($payload, $violations) : $item->getColor();
            $expectedVersion = $this->validatedVersion($payload, $violations);
            if (!$hasName && !$hasColor) {
                $violations[] = ['propertyPath' => 'name', 'message' => 'Provide a name or a color to change.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            if (null !== $expectedVersion && $expectedVersion !== $item->getVersion()) {
                return $this->staleError();
            }

            $changed = [];
            if ($name !== $item->getName()) {
                $sameName = $this->taxonomy->findByNameKey($this->itemClass(), $user, TaxonomyName::key($name));
                if (null !== $sameName && $sameName->getId() !== $item->getId()) {
                    return $this->duplicateError();
                }
                $item->rename($name);
                $changed[] = 'name';
            }
            if ($color !== $item->getColor()) {
                $item->setColor($color);
                $changed[] = 'color';
            }

            if ([] === $changed) {
                return $this->json(TaxonomyView::full($item));
            }

            $this->audit->record($this->event('updated'), $user, null, metadata: ['changed' => $changed], subjectId: $item->getId());

            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->staleError();
            } catch (UniqueConstraintViolationException) {
                return $this->duplicateError();
            }

            return $this->json(TaxonomyView::full($item));
        });
    }

    protected function deleteItem(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $item = $this->findItem($id, $user);
            if (null === $item) {
                return $this->notFound();
            }

            // Notes only lose the link (ON DELETE CASCADE / SET NULL); they are never deleted.
            $this->audit->record(
                $this->event('deleted'),
                $user,
                null,
                metadata: ['detached_notes' => $this->countNotesUsing($item)],
                subjectId: $item->getId(),
            );
            $this->entityManager->remove($item);

            try {
                $this->entityManager->flush();
            } catch (OptimisticLockException) {
                return $this->staleError();
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    abstract protected function countNotesUsing(TaxonomyItem $item): int;

    private function findItem(string $id, User $user): ?TaxonomyItem
    {
        return Uuid::isValid($id) ? $this->taxonomy->findOwned($this->itemClass(), $id, $user) : null;
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function validatedName(array $payload, array &$violations): string
    {
        $name = $payload['name'] ?? null;
        if (null !== $name && !is_string($name)) {
            $violations[] = ['propertyPath' => 'name', 'message' => 'Name must be a string.'];

            return '';
        }

        $name = TaxonomyName::normalize((string) $name);
        if ('' === $name) {
            $violations[] = ['propertyPath' => 'name', 'message' => 'Name is required.'];
        } elseif (!mb_check_encoding($name, 'UTF-8')) {
            $violations[] = ['propertyPath' => 'name', 'message' => 'Name must be valid text.'];
        } elseif (TaxonomyName::length($name) > TaxonomyName::MAX_LENGTH) {
            $violations[] = ['propertyPath' => 'name', 'message' => sprintf('Name cannot be longer than %d characters.', TaxonomyName::MAX_LENGTH)];
        }

        return $name;
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function validatedColor(array $payload, array &$violations): ?string
    {
        $color = $payload['color'] ?? null;
        if (null === $color) {
            return null;
        }

        if (!is_string($color) || 1 !== preg_match(self::COLOR_PATTERN, $color)) {
            $violations[] = ['propertyPath' => 'color', 'message' => 'Color must be a hex color like #1f77b4, or null.'];

            return null;
        }

        return strtolower($color);
    }

    private function event(string $action): string
    {
        return strtolower($this->label()).'.'.$action;
    }

    private function notFound(): JsonResponse
    {
        return $this->error($this->label().' not found.', Response::HTTP_NOT_FOUND);
    }

    private function duplicateError(): JsonResponse
    {
        return $this->error(sprintf('A %s with this name already exists.', strtolower($this->label())), Response::HTTP_CONFLICT);
    }

    private function staleError(): JsonResponse
    {
        return $this->error($this->label().' was changed by another request. Reload and try again.', Response::HTTP_CONFLICT);
    }
}
