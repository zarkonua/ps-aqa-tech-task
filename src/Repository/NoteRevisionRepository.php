<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Note;
use App\Entity\NoteRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteRevision>
 */
final class NoteRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteRevision::class);
    }

    public function findOneByNumber(Note $note, int $revision): ?NoteRevision
    {
        return $this->findOneBy(['note' => $note, 'revision' => $revision]);
    }

    /**
     * @return list<NoteRevision> newest first
     */
    public function findPage(Note $note, int $page, int $itemsPerPage): array
    {
        return $this->findBy(['note' => $note], ['revision' => 'DESC'], $itemsPerPage, ($page - 1) * $itemsPerPage);
    }

    public function countByNote(Note $note): int
    {
        return $this->count(['note' => $note]);
    }

    /**
     * Drops everything older than the newest $keep revisions of the note.
     */
    public function prune(Note $note, int $keep): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.note = :note AND r.revision <= :oldest')
            ->setParameter('note', $note)
            ->setParameter('oldest', $note->getVersion() - $keep)
            ->getQuery()
            ->execute();
    }
}
