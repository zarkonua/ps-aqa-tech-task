<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\TaxonomyName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxonomyNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['Work', 'Work', 'work'];
        yield 'trimmed' => ["  Work\n", 'Work', 'work'];
        yield 'inner whitespace collapsed' => ["To \t  do", 'To do', 'to do'];
        yield 'non-breaking and wide spaces' => ["\u{00A0}To\u{3000}do\u{00A0}", 'To do', 'to do'];
        yield 'unicode case' => ['ÜBER Straße', 'ÜBER Straße', 'über straße'];
        yield 'decomposed accents are composed' => ["Cafe\u{0301}", 'Café', 'café'];
        yield 'cjk untouched' => ['日本語', '日本語', '日本語'];
        yield 'emoji untouched' => ['Idea 💡', 'Idea 💡', 'idea 💡'];
        yield 'only whitespace' => [" \t\u{00A0}", '', ''];
        yield 'control characters' => ["a\u{0000}b", 'a b', 'a b'];
    }

    #[DataProvider('names')]
    public function testNormalizeAndKey(string $input, string $expectedName, string $expectedKey): void
    {
        $name = TaxonomyName::normalize($input);

        self::assertSame($expectedName, $name);
        self::assertSame($expectedKey, TaxonomyName::key($name));
        self::assertSame($name, TaxonomyName::normalize($name), 'normalizing twice changes nothing');
    }

    public function testLengthCountsCharactersNotBytes(): void
    {
        self::assertSame(30, TaxonomyName::length(str_repeat('日', 30)));
        self::assertSame(6, TaxonomyName::length('Idea 💡'));
    }
}
