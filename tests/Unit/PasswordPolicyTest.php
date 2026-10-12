<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testLengthIsCountedInCharacters(): void
    {
        self::assertNull(PasswordPolicy::violation(str_repeat('a', 12)));
        self::assertNull(PasswordPolicy::violation(str_repeat('a', 128)));
        self::assertNull(PasswordPolicy::violation(str_repeat('日', 12)));
        self::assertNull(PasswordPolicy::violation('with spaces and 💡 emoji'));

        self::assertSame('Password must be at least 12 characters.', PasswordPolicy::violation(str_repeat('a', 11)));
        self::assertSame('Password must be at least 12 characters.', PasswordPolicy::violation(str_repeat('日', 11)));
        self::assertSame('Password cannot be longer than 128 characters.', PasswordPolicy::violation(str_repeat('a', 129)));
    }

    public function testMissingAndMistypedValues(): void
    {
        self::assertSame('Password is required.', PasswordPolicy::violation(null));
        self::assertSame('Password is required.', PasswordPolicy::violation(''));
        foreach ([123456789012, true, ['long-enough-password'], 1.5] as $value) {
            self::assertSame('Password must be a string.', PasswordPolicy::violation($value));
        }
    }
}
