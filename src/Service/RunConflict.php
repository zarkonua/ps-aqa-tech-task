<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A change to a test run that cannot be made in its current state.
 */
final class RunConflict extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $quota = false)
    {
        parent::__construct($message);
    }
}
