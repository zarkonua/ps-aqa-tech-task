<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user who has a note open right now. Rows are written and read with plain SQL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'note_presence')]
#[ORM\Index(name: 'idx_note_presence_seen', columns: ['last_seen_at'])]
class NotePresence
{
    #[ORM\Id]
    #[ORM\Column(name: 'note_id', length: 36)]
    private string $noteId = '';

    #[ORM\Id]
    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId = '';

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    private function __construct()
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
