<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Note;
use App\Entity\NoteAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteAttachment>
 */
final class NoteAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteAttachment::class);
    }

    /**
     * @return list<NoteAttachment>
     */
    public function findByNote(Note $note): array
    {
        return $this->findBy(['note' => $note], ['createdAt' => 'ASC', 'filename' => 'ASC', 'id' => 'ASC']);
    }

    public function countByNote(Note $note): int
    {
        return $this->count(['note' => $note]);
    }

    /**
     * @param list<string> $ownerIds
     *
     * @return list<string> storage keys of every attachment on notes owned by these users
     */
    public function storageKeysOfOwners(array $ownerIds): array
    {
        if ([] === $ownerIds) {
            return [];
        }

        return array_column(
            $this->createQueryBuilder('a')
                ->select('a.storageKey')
                ->join('a.note', 'n')
                ->where('IDENTITY(n.owner) IN (:owners)')
                ->setParameter('owners', $ownerIds)
                ->getQuery()
                ->getScalarResult(),
            'storageKey',
        );
    }
}
