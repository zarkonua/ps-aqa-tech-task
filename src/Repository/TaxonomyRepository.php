<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaxonomyItem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Queries shared by tags and categories. Every lookup is scoped to an owner,
 * so another user's items are simply not found.
 */
final class TaxonomyRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @template T of TaxonomyItem
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function findOwned(string $class, string $id, User $owner): ?TaxonomyItem
    {
        return $this->entityManager->getRepository($class)->findOneBy(['id' => $id, 'owner' => $owner]);
    }

    /**
     * @template T of TaxonomyItem
     *
     * @param class-string<T> $class
     * @param list<string>    $ids
     *
     * @return list<T>
     */
    public function findOwnedByIds(string $class, array $ids, User $owner): array
    {
        return [] === $ids ? [] : $this->entityManager->getRepository($class)->findBy(['id' => $ids, 'owner' => $owner]);
    }

    /**
     * @param class-string<TaxonomyItem> $class
     */
    public function findByNameKey(string $class, User $owner, string $nameKey): ?TaxonomyItem
    {
        return $this->entityManager->getRepository($class)->findOneBy(['owner' => $owner, 'nameKey' => $nameKey]);
    }

    /**
     * @template T of TaxonomyItem
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function findPage(string $class, User $owner, ?string $search, int $page, int $itemsPerPage): array
    {
        return $this->ownedQuery($class, $owner, $search)
            ->orderBy('t.nameKey', 'ASC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param class-string<TaxonomyItem> $class
     */
    public function count(string $class, User $owner, ?string $search = null): int
    {
        return (int) $this->ownedQuery($class, $owner, $search)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * @param class-string<TaxonomyItem> $class
     */
    private function ownedQuery(string $class, User $owner, ?string $search): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from($class, 't')
            ->where('t.owner = :owner')
            ->setParameter('owner', $owner);

        if (null !== $search && '' !== $search) {
            $queryBuilder
                ->andWhere("t.nameKey LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%');
        }

        return $queryBuilder;
    }
}
