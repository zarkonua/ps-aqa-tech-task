<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What may be attached to a note: size, count, file types, and the name it is stored under.
 */
final class AttachmentPolicy
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_PER_NOTE = 5;
    public const MAX_FILENAME_LENGTH = 150;

    /** extension => [MIME type served, MIME types the content may be detected as ("text/" matches any text type)] */
    private const TYPES = [
        'png' => ['image/png', ['image/png']],
        'jpg' => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'pdf' => ['application/pdf', ['application/pdf']],
        'txt' => ['text/plain', ['text/', 'application/csv', 'application/json']],
        'csv' => ['text/csv', ['text/', 'application/csv']],
    ];

    /**
     * The type is decided by the file's content and must agree with its extension;
     * what the client claims in Content-Type is ignored.
     *
     * @return string|null the MIME type to store, or null when the file is not an allowed type
     */
    public static function mimeTypeFor(string $filename, string $path): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            return null;
        }

        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        foreach (self::TYPES[$extension][1] as $accepted) {
            if ($detected === $accepted || (str_ends_with($accepted, '/') && str_starts_with($detected, $accepted))) {
                return self::TYPES[$extension][0];
            }
        }

        return null;
    }

    /**
     * Reduces a client-supplied name to a plain file name: no directories, no control or
     * reserved characters, bounded length. The extension is kept.
     */
    public static function sanitizeFilename(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }

        // Keep only what follows the last slash or backslash, whichever style the client used.
        $name = (string) preg_replace('~^.*[\\\\/]~u', '', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"|?*\p{Cf}]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = '' === $extension ? $name : mb_substr($name, 0, -(mb_strlen($extension) + 1));
        $base = trim($base, ' .');
        if ('' === $base) {
            $base = 'file';
        }
        $base = mb_substr($base, 0, self::MAX_FILENAME_LENGTH - mb_strlen($extension) - 1);

        return '' === $extension ? $base : $base.'.'.strtolower($extension);
    }
}
