<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * The time as a user's test run sees it. Outside test runs this is simply the current time;
 * for a persona of a run whose clock was advanced, it is that much later.
 */
final class RunClock
{
    private const PERSONA_EMAIL = '/^[a-z]+\.([a-z0-9][a-z0-9-]{0,31})@qa-fixtures\.test$/D';

    /** @var array<string, int> run ID => offset, for this request */
    private array $offsets = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function now(?User $user = null): \DateTimeImmutable
    {
        $offset = $this->offsetFor($user);

        return 0 === $offset ? new \DateTimeImmutable() : (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $offset));
    }

    public function offsetFor(?User $user): int
    {
        if (null === $user || 1 !== preg_match(self::PERSONA_EMAIL, $user->getEmail(), $m)) {
            return 0;
        }

        return $this->offsets[$m[1]] ??= (int) $this->connection->fetchOne('SELECT clock_offset_seconds FROM test_runs WHERE id = ?', [$m[1]]);
    }

    public function forget(): void
    {
        $this->offsets = [];
    }
}
