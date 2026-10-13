<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tells whether a catalogued defect is switched on for the test run a user belongs to.
 * Real users belong to no run, and in production nothing is ever switched on.
 */
final class BugSwitch
{
    private const PERSONA_EMAIL = '/^[a-z]+\.([a-z0-9][a-z0-9-]{0,31})@qa-fixtures\.test$/D';

    /** @var array<string, list<string>> run ID => active bug IDs, for this request */
    private array $active = [];

    public function __construct(
        private readonly Connection $connection,
        // The token storage itself, not the Security helper: that one depends on the voters, which depend on this.
        private readonly TokenStorageInterface $tokens,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /**
     * @param User|null $user whose run decides; the signed-in user when left out
     */
    public function isActive(string $bugId, ?User $user = null): bool
    {
        if ('prod' === $this->environment) {
            return false;
        }

        $user ??= $this->tokens->getToken()?->getUser();
        if (!$user instanceof User || 1 !== preg_match(self::PERSONA_EMAIL, $user->getEmail(), $m)) {
            return false;
        }

        if (!isset($this->active[$m[1]])) {
            $stored = $this->connection->fetchOne('SELECT active_bugs FROM test_runs WHERE id = ?', [$m[1]]);
            $this->active[$m[1]] = is_string($stored) ? (array) json_decode($stored, true) : [];
        }

        return in_array($bugId, $this->active[$m[1]], true);
    }

    public function forget(): void
    {
        $this->active = [];
    }
}
