<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\User;
use App\Repository\NoteRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Version numbers and history of notes. A note's version is the number of its latest
 * revision; it moves only when title or content really change.
 */
final class NoteRevisions
{
    public const KEEP = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NoteRevisionRepository $revisions,
    ) {
    }

    /**
     * Reads the version a client expects from an If-Match header.
     *
     * @return int|null the expected version, or null for "*" or no header
     *
     * @throws PreconditionFailedHttpException when the header is not one of our ETags
     */
    public static function expectedVersion(?string $ifMatch): ?int
    {
        $ifMatch = trim((string) $ifMatch);
        if ('' === $ifMatch || '*' === $ifMatch) {
            return null;
        }

        if (1 !== preg_match('/^(?:W\/)?"?(\d{1,9})"?$/D', $ifMatch, $matches)) {
            throw new PreconditionFailedHttpException('If-Match does not name a version of this note.');
        }

        return (int) $matches[1];
    }

    public static function etag(Note $note): string
    {
        return sprintf('"%d"', $note->getVersion());
    }

    /**
     * Takes the next version number for the note, atomically.
     *
     * @param int $from the version the change is based on
     *
     * @return bool false when the note has moved on from that version in the meantime
     */
    public function claimNext(Note $note, int $from): bool
    {
        return 1 === $this->entityManager->getConnection()->executeStatement(
            'UPDATE notes SET version = version + 1 WHERE id = ? AND version = ?',
            [(string) $note->getId(), $from],
        );
    }

    public function currentVersion(Note $note): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT version FROM notes WHERE id = ?', [(string) $note->getId()]);
    }

    /**
     * Queues the snapshot of the note's current text as its latest revision
     * and drops revisions beyond the retention limit. The caller flushes.
     */
    public function record(Note $note, string $kind, ?User $actor, ?int $restoredFrom = null): NoteRevision
    {
        $revision = new NoteRevision($note, $note->getVersion(), $kind, $actor?->getId(), $restoredFrom);
        $this->entityManager->persist($revision);
        if ($note->getVersion() > self::KEEP) {
            $this->revisions->prune($note, self::KEEP);
        }

        return $revision;
    }
}
