<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Rules for passwords set through reset or change.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    /**
     * @return string|null what is wrong with the password, or null when it is acceptable
     */
    public static function violation(mixed $password): ?string
    {
        if (null === $password || '' === $password) {
            return 'Password is required.';
        }

        if (!is_string($password)) {
            return 'Password must be a string.';
        }

        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MIN_LENGTH) {
            return sprintf('Password must be at least %d characters.', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            return sprintf('Password cannot be longer than %d characters.', self::MAX_LENGTH);
        }

        return null;
    }
}
