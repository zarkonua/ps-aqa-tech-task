<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Restricts every Note query to notes the current user owns or has a share on.
 * Notes outside that set are invisible (404), never forbidden.
 */
final class NoteAccessExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public const SCOPE_OWNED = 'owned';
    public const SCOPE_SHARED = 'shared';
    public const SCOPE_ALL = 'all';
    public const SCOPES = [self::SCOPE_OWNED, self::SCOPE_SHARED, self::SCOPE_ALL];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        // The list keeps returning only owned notes unless a scope is requested.
        $scope = $context['filters']['scope'] ?? self::SCOPE_OWNED;
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            $scope = self::SCOPE_OWNED;
        }

        $this->addAccessFilter($queryBuilder, $queryNameGenerator, $resourceClass, $scope);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->addAccessFilter($queryBuilder, $queryNameGenerator, $resourceClass, self::SCOPE_ALL);
    }

    private function addAccessFilter(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $scope): void
    {
        if (Note::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Authenticated user is required.');
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $userParameter = $queryNameGenerator->generateParameterName('currentUser');
        $shareAlias = $queryNameGenerator->generateJoinAlias('share');

        $owned = sprintf('%s.owner = :%s', $rootAlias, $userParameter);
        $shared = sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.note = %s AND %s.user = :%s)',
            NoteShare::class,
            $shareAlias,
            $shareAlias,
            $rootAlias,
            $shareAlias,
            $userParameter,
        );

        $queryBuilder
            ->andWhere(match ($scope) {
                self::SCOPE_SHARED => $shared,
                self::SCOPE_ALL => sprintf('(%s OR %s)', $owned, $shared),
                default => $owned,
            })
            ->setParameter($userParameter, $user);
    }
}
