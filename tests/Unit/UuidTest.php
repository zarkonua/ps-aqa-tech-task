<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SharingFixtures;
use App\Util\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneratedIdsAreValidAndUnique(): void
    {
        $a = Uuid::v4();
        self::assertTrue(Uuid::isValid($a));
        self::assertNotSame($a, Uuid::v4());
    }

    public function testNamedIdsAreStable(): void
    {
        self::assertSame(Uuid::fromName('run/owner'), Uuid::fromName('run/owner'));
        self::assertNotSame(Uuid::fromName('run/owner'), Uuid::fromName('run/viewer'));
        self::assertTrue(Uuid::isValid(Uuid::fromName('run/owner')));
    }

    public function testInvalidIds(): void
    {
        foreach (['', 'not-a-uuid', '00000000-0000-4000-8000-00000000000', "00000000-0000-4000-8000-000000000000\n", '../../etc/passwd'] as $value) {
            self::assertFalse(Uuid::isValid($value), var_export($value, true));
        }
    }

    public function testRunIdValidation(): void
    {
        SharingFixtures::assertValidRunId('run-2026-10-12');

        foreach (['', '-run', 'Run', 'run id', str_repeat('a', 33), "run'; --"] as $runId) {
            try {
                SharingFixtures::assertValidRunId($runId);
                self::fail('accepted '.var_export($runId, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
