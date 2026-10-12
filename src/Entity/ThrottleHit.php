<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One counted attempt of a rate-limited action. The key (email, IP, user ID) is stored hashed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'throttle_hits')]
#[ORM\Index(name: 'idx_throttle_hits_lookup', columns: ['bucket', 'key_hash', 'created_at'])]
class ThrottleHit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(length: 64)]
    private string $bucket;

    #[ORM\Column(name: 'key_hash', length: 64)]
    private string $keyHash;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $bucket, string $keyHash)
    {
        $this->bucket = $bucket;
        $this->keyHash = $keyHash;
        $this->createdAt = new \DateTimeImmutable();
    }
}
