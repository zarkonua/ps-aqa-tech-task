<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
final class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Tasks the user owns or is assigned to, narrowed by the given filters.
     *
     * @param array{status?: TaskStatus, priority?: string, assignee?: string, owner?: string, note?: string, q?: string} $filters
     */
    public function visibleTo(User $user, array $filters = []): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('t')
            ->where('(t.owner = :user OR t.assignee = :user)')
            ->setParameter('user', $user);

        if (isset($filters['status'])) {
            $queryBuilder->andWhere('t.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['priority'])) {
            $queryBuilder->andWhere('t.priority = :priority')->setParameter('priority', $filters['priority']);
        }
        if (isset($filters['assignee'])) {
            if ('none' === $filters['assignee']) {
                $queryBuilder->andWhere('t.assignee IS NULL');
            } else {
                $queryBuilder->andWhere('IDENTITY(t.assignee) = :assignee')->setParameter('assignee', $filters['assignee']);
            }
        }
        if (isset($filters['note'])) {
            $queryBuilder->andWhere('IDENTITY(t.note) = :note')->setParameter('note', $filters['note']);
        }
        if (isset($filters['q']) && '' !== $filters['q']) {
            $queryBuilder
                ->andWhere("(LOWER(t.title) LIKE :q ESCAPE '!' OR LOWER(t.description) LIKE :q ESCAPE '!')")
                ->setParameter('q', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%');
        }

        return $queryBuilder;
    }

    /**
     * The owner's tasks in one column, in board order, locked until the transaction ends.
     *
     * @return list<Task>
     */
    public function lockColumn(User $owner, TaskStatus $status): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.owner = :owner AND t.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', $status)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    public function nextPosition(User $owner, TaskStatus $status): int
    {
        return 1 + (int) $this->createQueryBuilder('t')
            ->select('COALESCE(MAX(t.position), -1)')
            ->where('t.owner = :owner AND t.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
