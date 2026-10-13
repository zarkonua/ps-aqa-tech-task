<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Note;
use App\Service\BugCatalog;
use App\Service\BugSwitch;
use App\State\NoteCollectionProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * Date range and ordering of the notes list. Runs after the built-in filters,
 * so it sees (and may replace) the ordering asked for with the legacy "sort[...]" parameters.
 */
final class NoteSearchExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly BugSwitch $bugs,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Note::class !== $resourceClass) {
            return;
        }

        $search = $context[NoteCollectionProvider::CONTEXT_KEY] ?? [];
        $rootAlias = $queryBuilder->getRootAliases()[0];

        foreach (['from' => '>=', 'to' => '<='] as $bound => $operator) {
            if (($search[$bound] ?? null) instanceof \DateTimeImmutable) {
                $parameter = $queryNameGenerator->generateParameterName('updated'.ucfirst($bound));
                $queryBuilder
                    ->andWhere(sprintf('%s.updatedAt %s :%s', $rootAlias, $operator, $parameter))
                    ->setParameter($parameter, $search[$bound], Types::DATETIME_IMMUTABLE);
            }
        }

        $sortBy = $search['sortBy'] ?? null;
        $order = $search['order'] ?? null;
        $legacySort = $context['filters']['sort'] ?? null;
        if (null !== $sortBy || null !== $order || !is_array($legacySort) || [] === $legacySort) {
            $direction = $order ?? (null === $sortBy || 'title' !== $sortBy ? 'desc' : 'asc');
            // Injected defect BUG-005, only for test runs that have it switched on.
            if ('title' === $sortBy && $this->bugs->isActive(BugCatalog::SORTING)) {
                $direction = 'asc' === $direction ? 'desc' : 'asc';
            }
            $queryBuilder->resetDQLPart('orderBy');
            $queryBuilder->orderBy(sprintf('%s.%s', $rootAlias, $sortBy ?? 'updatedAt'), strtoupper($direction));
        }

        // Notes with equal sort values keep one fixed order, so pages never overlap or skip.
        $queryBuilder->addOrderBy($rootAlias.'.id', 'ASC');
    }
}
