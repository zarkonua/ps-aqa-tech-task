<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Sliding-window rate limiter backed by the database, so limits hold across
 * web workers and can be reset together with other test data.
 */
final class Throttle
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return int|null seconds until the next attempt is allowed, or null when one is allowed now
     */
    public function retryAfter(string $bucket, string $key, int $limit, int $windowSeconds): ?int
    {
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS hits, MIN(created_at) AS oldest FROM throttle_hits WHERE bucket = ? AND key_hash = ? AND created_at > ?',
            [$bucket, self::hashKey($key), $since],
        );

        if ((int) ($row['hits'] ?? 0) < $limit) {
            return null;
        }

        $oldest = strtotime($row['oldest'].' UTC');

        return max(1, $oldest + $windowSeconds - time());
    }

    public function record(string $bucket, string $key): void
    {
        $keyHash = self::hashKey($key);
        // Old rows of this key are of no use to any window.
        $this->connection->executeStatement(
            'DELETE FROM throttle_hits WHERE bucket = ? AND key_hash = ? AND created_at < ?',
            [$bucket, $keyHash, gmdate('Y-m-d H:i:s', time() - 86400)],
        );
        $this->connection->insert('throttle_hits', ['bucket' => $bucket, 'key_hash' => $keyHash, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Counts an attempt if one is allowed.
     *
     * @return int|null seconds to wait when the limit is used up, null when the attempt was counted
     */
    public function attempt(string $bucket, string $key, int $limit, int $windowSeconds): ?int
    {
        $retryAfter = $this->retryAfter($bucket, $key, $limit, $windowSeconds);
        if (null === $retryAfter) {
            $this->record($bucket, $key);
        }

        return $retryAfter;
    }

    public function clear(string $bucket, string $key): void
    {
        $this->connection->executeStatement('DELETE FROM throttle_hits WHERE bucket = ? AND key_hash = ?', [$bucket, self::hashKey($key)]);
    }

    /**
     * Removes every counted attempt of the given keys, in all buckets (used when purging fixtures).
     *
     * @param list<string> $keys
     */
    public function forget(array $keys): void
    {
        foreach ($keys as $key) {
            $this->connection->executeStatement('DELETE FROM throttle_hits WHERE key_hash = ?', [self::hashKey($key)]);
        }
    }

    private static function hashKey(string $key): string
    {
        return hash('sha256', strtolower(trim($key)));
    }
}
