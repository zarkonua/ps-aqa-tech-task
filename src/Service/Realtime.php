<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RealtimeEvent;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Event log and presence behind the realtime stream and its polling fallback.
 */
final class Realtime
{
    public const PRESENCE_TTL_SECONDS = 30;
    public const EVENT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $payload     what clients are told; never note text
     * @param string|null          $onlyForUser deliver to this user's streams only
     */
    public function publish(string $noteId, string $type, array $payload = [], ?string $onlyForUser = null): void
    {
        $this->connection->insert('realtime_events', [
            'public_id' => bin2hex(random_bytes(16)),
            'note_id' => $noteId,
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'only_for_user_id' => $onlyForUser,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        // Events only matter to clients that are connected or were a moment ago.
        $this->connection->executeStatement('DELETE FROM realtime_events WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - self::EVENT_TTL_SECONDS)]);
    }

    /**
     * Where a stream that starts now begins: after everything that already happened.
     */
    public function latestPosition(string $noteId): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM realtime_events WHERE note_id = ?', [$noteId]);
    }

    /**
     * The position of an event a client names as the last one it saw.
     *
     * @return int|null null when the event is unknown or no longer kept: the client has to resync
     */
    public function positionOf(string $noteId, string $publicId): ?int
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return null;
        }
        $position = $this->connection->fetchOne('SELECT id FROM realtime_events WHERE note_id = ? AND public_id = ?', [$noteId, $publicId]);

        return false === $position ? null : (int) $position;
    }

    /**
     * Events of the note after a position that this user may get, oldest first.
     *
     * @return list<array{position: int, id: string, type: string, data: array<string, mixed>, created_at: string}>
     */
    public function eventsAfter(string $noteId, int $position, User $user, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, public_id, type, payload, created_at FROM realtime_events WHERE note_id = ? AND id > ? AND (only_for_user_id IS NULL OR only_for_user_id = ?) ORDER BY id ASC LIMIT '.$limit,
            [$noteId, $position, $user->getId()],
        );

        return array_map(static fn (array $row): array => [
            'position' => (int) $row['id'],
            'id' => $row['public_id'],
            'type' => $row['type'],
            'data' => json_decode($row['payload'], true, 16, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ], $rows);
    }

    /**
     * Records that the user has the note open. Announces them if they were not present,
     * and announces the departure of anyone who has not been heard from for the TTL.
     */
    public function heartbeat(string $noteId, User $user): void
    {
        $this->expirePresence($noteId);

        $affected = $this->connection->executeStatement(
            'INSERT INTO note_presence (note_id, user_id, last_seen_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)',
            [$noteId, $user->getId(), gmdate('Y-m-d H:i:s')],
        );
        // MySQL reports 1 for a new row and 2 (or 0) for an existing one.
        if (1 === $affected) {
            $this->publish($noteId, RealtimeEvent::PRESENCE_JOINED, ['user' => self::person($user)]);
        }
    }

    public function leave(string $noteId, User $user): void
    {
        if (1 === $this->connection->executeStatement('DELETE FROM note_presence WHERE note_id = ? AND user_id = ?', [$noteId, $user->getId()])) {
            $this->publish($noteId, RealtimeEvent::PRESENCE_LEFT, ['user' => self::person($user)]);
        }
    }

    /**
     * @return list<array{id: string, email: string}> who has the note open, by email
     */
    public function presence(string $noteId): array
    {
        $this->expirePresence($noteId);

        return $this->connection->fetchAllAssociative(
            'SELECT u.id, u.email FROM note_presence p JOIN users u ON u.id = p.user_id WHERE p.note_id = ? ORDER BY u.email',
            [$noteId],
        );
    }

    /**
     * Removes everything kept for a note or for users (used when purging fixtures).
     *
     * @param list<string> $noteIds
     * @param list<string> $userIds
     */
    public function forget(array $noteIds, array $userIds): void
    {
        if ([] !== $noteIds) {
            $this->connection->executeStatement('DELETE FROM realtime_events WHERE note_id IN (?)', [$noteIds], [ArrayParameterType::STRING]);
            $this->connection->executeStatement('DELETE FROM note_presence WHERE note_id IN (?)', [$noteIds], [ArrayParameterType::STRING]);
        }
        if ([] !== $userIds) {
            $this->connection->executeStatement('DELETE FROM note_presence WHERE user_id IN (?)', [$userIds], [ArrayParameterType::STRING]);
        }
    }

    private function expirePresence(string $noteId): void
    {
        $stale = $this->connection->fetchAllAssociative(
            'SELECT p.user_id AS id, u.email FROM note_presence p LEFT JOIN users u ON u.id = p.user_id WHERE p.note_id = ? AND p.last_seen_at < ?',
            [$noteId, gmdate('Y-m-d H:i:s', time() - self::PRESENCE_TTL_SECONDS)],
        );
        foreach ($stale as $person) {
            // Whoever deletes the row announces the departure, so it is announced once.
            if (1 === $this->connection->executeStatement('DELETE FROM note_presence WHERE note_id = ? AND user_id = ? AND last_seen_at < ?', [$noteId, $person['id'], gmdate('Y-m-d H:i:s', time() - self::PRESENCE_TTL_SECONDS)])) {
                $this->publish($noteId, RealtimeEvent::PRESENCE_LEFT, ['user' => ['id' => $person['id'], 'email' => $person['email']]]);
            }
        }
    }

    /**
     * @return array{id: string, email: string}
     */
    private static function person(User $user): array
    {
        return ['id' => (string) $user->getId(), 'email' => $user->getEmail()];
    }
}
