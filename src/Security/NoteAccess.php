<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Note;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Repository\NoteShareRepository;

/**
 * Single source of truth for "what may this user do with this note".
 * Always reads the current share from the database, so a revocation or
 * role change applies to the very next request.
 */
final class NoteAccess
{
    public const ROLE_OWNER = 'owner';

    public function __construct(
        private readonly NoteShareRepository $shares,
    ) {
    }

    /**
     * @return string|null "owner", "editor", "viewer" or null when the user has no access
     */
    public function roleFor(Note $note, User $user): ?string
    {
        if ($this->isOwner($note, $user)) {
            return self::ROLE_OWNER;
        }

        return $this->shares->findOneByNoteAndUser($note, $user)?->getRole()->value;
    }

    public function isOwner(Note $note, User $user): bool
    {
        $ownerId = $note->getOwner()?->getId();

        return null !== $ownerId && $ownerId === $user->getId();
    }

    public function canView(Note $note, User $user): bool
    {
        return null !== $this->roleFor($note, $user);
    }

    public function canEdit(Note $note, User $user): bool
    {
        return in_array($this->roleFor($note, $user), [self::ROLE_OWNER, ShareRole::Editor->value], true);
    }
}
