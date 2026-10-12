<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountToken>
 */
final class AccountTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountToken::class);
    }

    public function findByPlainToken(string $purpose, string $plainToken): ?AccountToken
    {
        return $this->findOneBy(['purpose' => $purpose, 'tokenHash' => AccountToken::hash($plainToken)]);
    }

    public function findPending(User $user, string $purpose): ?AccountToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user AND t.purpose = :purpose AND t.usedAt IS NULL AND t.expiresAt >= :now')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Marks the token used unless a concurrent request already did.
     *
     * @return bool true when this call is the one that consumed it
     */
    public function consume(AccountToken $token): bool
    {
        return 1 === $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.id = :id AND t.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute();
    }

    /**
     * A newer request replaces the older ones: their still valid tokens stop working.
     */
    public function revokePending(User $user, string $purpose): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.user = :user AND t.purpose = :purpose AND t.usedAt IS NULL AND t.expiresAt >= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->execute();
    }
}
