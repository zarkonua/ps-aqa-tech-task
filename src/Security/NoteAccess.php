<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Note;
use App\Entity\User;
use App\Enum\ShareRole;
use App\Repository\NoteShareRepository;
use App\Service\BugCatalog;
use App\Service\BugSwitch;

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
        private readonly BugSwitch $bugs,
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
        $role = $this->roleFor($note, $user);

        return in_array($role, [self::ROLE_OWNER, ShareRole::Editor->value], true)
            // Injected defect BUG-002, only for test runs that have it switched on.
            || (ShareRole::Viewer->value === $role && $this->bugs->isActive(BugCatalog::AUTH_BYPASS, $user));
    }
}
