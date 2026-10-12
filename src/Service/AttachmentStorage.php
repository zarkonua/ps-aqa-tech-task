<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Attachment files on disk, outside the web root. Files are named by a random key;
 * nothing a client sends (name, extension, path) ever becomes part of a path.
 */
final class AttachmentStorage
{
    private const KEY_PATTERN = '/^[a-f0-9]{32}$/D';

    public function __construct(
        #[Autowire('%app.attachments_dir%')]
        private readonly string $directory,
    ) {
    }

    /**
     * Copies a file into storage.
     *
     * @return string the key to find it by
     */
    public function store(string $sourcePath): string
    {
        $key = bin2hex(random_bytes(16));
        $target = $this->path($key);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Attachment storage is not writable.');
        }

        if (!copy($sourcePath, $target)) {
            throw new \RuntimeException('Attachment could not be stored.');
        }
        chmod($target, 0640);

        return $key;
    }

    public function storeContent(string $content): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'attachment');
        file_put_contents($temporary, $content);

        try {
            return $this->store($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    public function path(string $key): string
    {
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException('Invalid storage key.');
        }

        return sprintf('%s/%s/%s', rtrim($this->directory, '/'), substr($key, 0, 2), $key);
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    /**
     * @param iterable<string> $keys
     */
    public function delete(iterable $keys): void
    {
        foreach ($keys as $key) {
            $path = $this->path($key);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return list<string> keys of all stored files
     */
    public function keys(): array
    {
        $keys = [];
        foreach (glob(rtrim($this->directory, '/').'/*/*') ?: [] as $path) {
            if (is_file($path) && 1 === preg_match(self::KEY_PATTERN, basename($path))) {
                $keys[] = basename($path);
            }
        }

        return $keys;
    }
}
