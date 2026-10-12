<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Category;
use App\Entity\Note;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\NoteShareRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The changes to a note that several entry points share (single-note routes and bulk operations),
 * each with its audit record. Callers have already checked that the actor may make the change.
 */
final class NoteMutator
{
    public const MAX_TAGS_PER_NOTE = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NoteShareRepository $shares,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Makes the note carry exactly the tags the callback returns for its current ones.
     * The note row is locked meanwhile, so concurrent changes cannot add up past the limit.
     *
     * @param \Closure(list<Tag>): list<Tag> $change
     *
     * @return bool false when the result would exceed the tag limit (nothing is changed then)
     */
    public function changeTags(Note $note, User $actor, \Closure $change): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($note, $actor, $change): bool {
            $this->entityManager->lock($note, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($note);

            $current = array_values($note->getTags()->toArray());
            $wanted = $change($current);
            if (count($wanted) > self::MAX_TAGS_PER_NOTE) {
                return false;
            }

            $before = array_map(static fn (Tag $tag): string => $tag->getId(), $current);
            $after = array_values(array_unique(array_map(static fn (Tag $tag): string => $tag->getId(), $wanted)));
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ([] !== $added || [] !== $removed) {
                $note->replaceTags($wanted);
                $this->audit->record(AuditEvent::NOTE_TAGS_CHANGED, $actor, (string) $note->getId(), metadata: ['added' => $added, 'removed' => $removed]);
            }

            return true;
        });
    }

    public function setCategory(Note $note, User $actor, ?Category $category): void
    {
        $previousId = $note->getCategory()?->getId();
        if ($previousId === $category?->getId()) {
            return;
        }

        $note->setCategory($category);
        $this->audit->record(AuditEvent::NOTE_CATEGORY_CHANGED, $actor, (string) $note->getId(), metadata: ['from' => $previousId, 'to' => $category?->getId()]);
        $this->entityManager->flush();
    }

    /**
     * Queues the audit records of a deletion: one for the note and one per share that goes with it.
     * The caller removes the note and flushes.
     */
    public function auditDeletion(Note $note, ?User $actor): void
    {
        foreach ($this->shares->findByNote($note) as $share) {
            $this->audit->record(
                AuditEvent::SHARE_REMOVED_WITH_NOTE,
                $actor,
                (string) $note->getId(),
                $share->getId(),
                $share->getUser()->getId(),
                ['role' => $share->getRole()->value],
            );
        }
        $this->audit->record(AuditEvent::NOTE_DELETED, $actor, (string) $note->getId());
    }

    public function delete(Note $note, User $actor): void
    {
        $this->auditDeletion($note, $actor);
        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }
}
