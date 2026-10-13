<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Note;
use App\Service\BugCatalog;
use App\Service\BugSwitch;
use Doctrine\ORM\QueryBuilder;

/**
 * Wraps the real pagination. Injected defect BUG-001, for test runs that have it switched on:
 * pages after the first start one note late.
 */
final class NotePaginationFaultExtension implements QueryResultCollectionExtensionInterface
{
    public function __construct(
        private readonly QueryResultCollectionExtensionInterface $pagination,
        private readonly BugSwitch $bugs,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->pagination->applyToCollection($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);

        if (Note::class === $resourceClass && $queryBuilder->getFirstResult() > 0 && $this->bugs->isActive(BugCatalog::PAGINATION)) {
            $queryBuilder->setFirstResult($queryBuilder->getFirstResult() + 1);
        }
    }

    public function supportsResult(string $resourceClass, ?Operation $operation = null, array $context = []): bool
    {
        return $this->pagination->supportsResult($resourceClass, $operation, $context);
    }

    public function getResult(QueryBuilder $queryBuilder, ?string $resourceClass = null, ?Operation $operation = null, array $context = []): iterable
    {
        return $this->pagination->getResult($queryBuilder, $resourceClass, $operation, $context);
    }
}
