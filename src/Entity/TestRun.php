<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One isolated set of test data: its own personas, notes and clock.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_runs')]
class TestRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $fixture;

    #[ORM\Column(name: 'fixture_version', type: 'integer')]
    private int $fixtureVersion;

    #[ORM\Column(type: 'integer')]
    private int $seed;

    /** What the run contains, as JSON: every identifier a test needs. */
    #[ORM\Column(type: 'text')]
    private string $manifest = '{}';

    /** Hash of the manifest without its run-specific identifiers: equal for runs with the same content. */
    #[ORM\Column(name: 'manifest_hash', length: 64)]
    private string $manifestHash = '';

    /** SHA-256 of the token that may operate on this run only. */
    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    /** How far this run's clock is ahead of the real one. */
    #[ORM\Column(name: 'clock_offset_seconds', type: 'integer', options: ['default' => 0])]
    private int $clockOffsetSeconds = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $resets = 0;

    /**
     * IDs of the catalogued defects switched on for this run.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'active_bugs', type: 'json', nullable: true)]
    private ?array $activeBugs = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $fixture, int $fixtureVersion, int $seed, string $tokenHash)
    {
        $this->id = $id;
        $this->fixture = $fixture;
        $this->fixtureVersion = $fixtureVersion;
        $this->seed = $seed;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFixture(): string
    {
        return $this->fixture;
    }

    public function getFixtureVersion(): int
    {
        return $this->fixtureVersion;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        return json_decode($this->manifest, true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function setManifest(array $manifest, string $hash, int $fixtureVersion): self
    {
        $this->manifest = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->manifestHash = $hash;
        $this->fixtureVersion = $fixtureVersion;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getManifestHash(): string
    {
        return $this->manifestHash;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getClockOffsetSeconds(): int
    {
        return $this->clockOffsetSeconds;
    }

    public function setClockOffsetSeconds(int $seconds): self
    {
        $this->clockOffsetSeconds = $seconds;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getActiveBugs(): array
    {
        return $this->activeBugs ?? [];
    }

    /**
     * @param list<string> $bugIds
     */
    public function setActiveBugs(array $bugIds): self
    {
        $this->activeBugs = [] === $bugIds ? null : array_values($bugIds);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getResets(): int
    {
        return $this->resets;
    }

    public function countReset(): self
    {
        ++$this->resets;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
