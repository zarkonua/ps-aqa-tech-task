<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use App\Entity\NoteShare;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The people a user already works with: those they shared a note with, and the owners
 * of notes shared with them. Only these can be picked as assignees, so assigning
 * cannot be used to find out which email addresses have accounts.
 */
final class Collaborators
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<User> the user first, then collaborators by email
     */
    public function of(User $user): array
    {
        $sharedByMe = $this->entityManager->createQuery(
            'SELECT u FROM '.User::class.' u WHERE u.id IN (SELECT IDENTITY(s.user) FROM '.NoteShare::class.' s JOIN s.note n WHERE n.owner = :user)'
        )->setParameter('user', $user)->getResult();
        $sharedWithMe = $this->entityManager->createQuery(
            'SELECT u FROM '.User::class.' u WHERE u.id IN (SELECT IDENTITY(n.owner) FROM '.NoteShare::class.' s JOIN s.note n WHERE s.user = :user)'
        )->setParameter('user', $user)->getResult();

        $people = [];
        foreach ([...$sharedByMe, ...$sharedWithMe] as $person) {
            if ($person->getId() !== $user->getId()) {
                $people[$person->getId()] = $person;
            }
        }
        usort($people, static fn (User $a, User $b): int => strcmp($a->getEmail(), $b->getEmail()));

        return [$user, ...$people];
    }

    public function find(User $user, string $collaboratorId): ?User
    {
        foreach ($this->of($user) as $person) {
            if ($person->getId() === $collaboratorId) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @return list<User> of the user's collaborators (and the user), those who can see the note
     */
    public function withAccessTo(User $user, Note $note, \Closure $canView): array
    {
        return array_values(array_filter($this->of($user), static fn (User $person): bool => $canView($note, $person)));
    }
}
