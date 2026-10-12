<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Tag and category names: one canonical display form, and a key that makes
 * "Work", "work" and " WORK " the same name.
 */
final class TaxonomyName
{
    public const MAX_LENGTH = 30;

    /**
     * Trims, collapses whitespace runs to a single space and applies Unicode NFC.
     */
    public static function normalize(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }

        return trim((string) preg_replace('/[\s\p{Z}\p{Cc}]+/u', ' ', $name));
    }

    /**
     * Case-insensitive identity of an already normalized name.
     */
    public static function key(string $normalizedName): string
    {
        return mb_strtolower($normalizedName, 'UTF-8');
    }

    public static function length(string $normalizedName): int
    {
        return mb_strlen($normalizedName, 'UTF-8');
    }
}
