<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteShare>
 */
final class NoteShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteShare::class);
    }

    public function findOneByNoteAndUser(Note $note, User $user): ?NoteShare
    {
        return $this->findOneBy(['note' => $note, 'user' => $user]);
    }

    /**
     * @return list<NoteShare>
     */
    public function findByNote(Note $note): array
    {
        return $this->findBy(['note' => $note]);
    }

    /**
     * @return list<NoteShare>
     */
    public function findPageByNote(Note $note, int $page, int $itemsPerPage): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('u')
            ->join('s.user', 'u')
            ->where('s.note = :note')
            ->setParameter('note', $note)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();
    }

    public function countByNote(Note $note): int
    {
        return $this->count(['note' => $note]);
    }
}
