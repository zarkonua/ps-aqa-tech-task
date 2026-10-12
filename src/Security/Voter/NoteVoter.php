<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Note;
use App\Entity\User;
use App\Security\NoteAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Note>
 */
final class NoteVoter extends Voter
{
    public const VIEW = 'NOTE_VIEW';
    public const EDIT = 'NOTE_EDIT';
    public const DELETE = 'NOTE_DELETE';
    public const MANAGE_SHARES = 'NOTE_MANAGE_SHARES';

    public function __construct(
        private readonly NoteAccess $access,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Note
            && in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::MANAGE_SHARES], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $this->access->canView($subject, $user),
            self::EDIT => $this->access->canEdit($subject, $user),
            self::DELETE, self::MANAGE_SHARES => $this->access->isOwner($subject, $user),
            default => false,
        };
    }
}
