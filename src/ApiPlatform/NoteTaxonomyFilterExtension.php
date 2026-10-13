<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Note;
use App\Service\BugCatalog;
use App\Service\BugSwitch;
use Doctrine\ORM\QueryBuilder;

/**
 * "tagIds", "tagMatch" and "categoryId" filters of the notes list. They only narrow
 * the result further; which notes are visible at all is decided by NoteAccessExtension.
 */
final class NoteTaxonomyFilterExtension implements QueryCollectionExtensionInterface
{
    public const MATCH_ANY = 'any';
    public const MATCH_ALL = 'all';
    public const TAG_MATCHES = [self::MATCH_ANY, self::MATCH_ALL];

    private const ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    public const ID_PATTERN = '/^'.self::ID.'$/D';
    public const TAG_IDS_PATTERN = '/^'.self::ID.'(,'.self::ID.'){0,9}$/D';

    public function __construct(
        private readonly BugSwitch $bugs,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Note::class !== $resourceClass) {
            return;
        }

        $filters = $context['filters'] ?? [];
        $rootAlias = $queryBuilder->getRootAliases()[0];

        $tagIds = $filters['tagIds'] ?? null;
        if (is_string($tagIds) && 1 === preg_match(self::TAG_IDS_PATTERN, $tagIds)) {
            $conditions = [];
            foreach (array_unique(explode(',', strtolower($tagIds))) as $tagId) {
                $parameter = $queryNameGenerator->generateParameterName('tag');
                $conditions[] = sprintf(':%s MEMBER OF %s.tags', $parameter, $rootAlias);
                $queryBuilder->setParameter($parameter, $tagId);
            }

            $all = self::MATCH_ALL === ($filters['tagMatch'] ?? self::MATCH_ANY);
            // Injected defect BUG-003, only for test runs that have it switched on.
            if ($this->bugs->isActive(BugCatalog::TAG_MATCH)) {
                $all = !$all;
            }
            $glue = $all ? ' AND ' : ' OR ';
            $queryBuilder->andWhere('('.implode($glue, $conditions).')');
        }

        $categoryId = $filters['categoryId'] ?? null;
        if (is_string($categoryId) && 1 === preg_match(self::ID_PATTERN, $categoryId)) {
            $parameter = $queryNameGenerator->generateParameterName('category');
            $queryBuilder
                ->andWhere(sprintf('IDENTITY(%s.category) = :%s', $rootAlias, $parameter))
                ->setParameter($parameter, strtolower($categoryId));
        }
    }
}
