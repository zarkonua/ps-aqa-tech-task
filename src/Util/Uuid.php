<?php

declare(strict_types=1);

namespace App\Util;

final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format(bin2hex($bytes));
    }

    /**
     * Deterministic UUID (v5 layout) derived from a name, used for repeatable fixtures.
     */
    public static function fromName(string $name): string
    {
        $bytes = substr(sha1($name, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format(bin2hex($bytes));
    }

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }

    private static function format(string $hex): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
