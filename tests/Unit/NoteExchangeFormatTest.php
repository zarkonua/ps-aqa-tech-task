<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\NoteExchangeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoteExchangeFormatTest extends TestCase
{
    public function testCsvRoundTripKeepsEveryCharacter(): void
    {
        $notes = [
            self::note('a-1', 'Plain', 'Simple text', ['work'], 'Projects'),
            self::note('a-2', 'Quotes "and", commas', "Line one\nLine two\r\nLine three, with a comma", ['a;b', 'back\\slash', 'ünï 日本'], null),
            self::note(null, '=SUM(1+1)', '+cmd|\' /C calc\'!A0', ['-minus', '@at'], '=cat'),
            self::note('a-4', "'starts with an apostrophe", "\ttab first", [], "'quoted"),
            self::note('a-5', '💡 emoji and Привет', '  leading and trailing spaces  ', [], null),
        ];

        $csv = NoteExchangeFormat::toCsv($notes);
        self::assertStringStartsWith("\xEF\xBB\xBF\"external_id\",\"title\",\"content\",\"tags\",\"category\",\"created_at\",\"updated_at\"\r\n", $csv);

        $parsed = NoteExchangeFormat::parseCsv($csv);
        self::assertSame([], $parsed['errors']);
        self::assertCount(5, $parsed['rows']);
        foreach ($notes as $index => $note) {
            $values = $parsed['rows'][$index]['values'];
            self::assertSame((string) $note['external_id'], $values['external_id']);
            self::assertSame($note['title'], $values['title']);
            self::assertSame($note['content'], $values['content']);
            self::assertSame($note['tags'], NoteExchangeFormat::splitTags($values['tags']));
            self::assertSame((string) $note['category'], $values['category']);
        }
        // Records that span lines are numbered by the line they start on (the header is line 1).
        self::assertSame([2, 3, 6, 7, 8], array_column($parsed['rows'], 'line'));
    }

    public function testNoCellInAnExportStartsAFormula(): void
    {
        $csv = NoteExchangeFormat::toCsv([self::note('=1+1', '=HYPERLINK("http://evil","x")', "-2+3\n=second line", ['+tag', '=other'], '@cat')]);

        $cells = [];
        preg_match_all('/"((?:[^"]|"")*)"/', substr($csv, 3), $matches);
        foreach ($matches[1] as $cell) {
            $cells[] = str_replace('""', '"', $cell);
        }
        foreach ($cells as $cell) {
            self::assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, 'cell: '.$cell);
        }
        self::assertContains("'=HYPERLINK(\"http://evil\",\"x\")", $cells);
        self::assertContains("'-2+3\n=second line", $cells);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function neutralized(): iterable
    {
        yield 'equals' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+1', "'+1"];
        yield 'minus' => ['-1', "'-1"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'apostrophe' => ["'text", "''text"];
        yield 'harmless' => ['a=1', 'a=1'];
        yield 'empty' => ['', ''];
        yield 'number' => ['42', '42'];
    }

    public function testOtherLeadingApostrophesAreKeptOnImport(): void
    {
        self::assertSame("'; DROP TABLE notes; --", NoteExchangeFormat::denormalize("'; DROP TABLE notes; --"));
        self::assertSame("'quoted' word", NoteExchangeFormat::denormalize("'quoted' word"));
        self::assertSame("'", NoteExchangeFormat::denormalize("'"));
        self::assertSame('=1+1', NoteExchangeFormat::denormalize("'=1+1"));
    }

    #[DataProvider('neutralized')]
    public function testNeutralizeIsReversible(string $value, string $expected): void
    {
        self::assertSame($expected, NoteExchangeFormat::neutralize($value));
        self::assertSame($value, NoteExchangeFormat::denormalize(NoteExchangeFormat::neutralize($value)));
    }

    public function testCsvAcceptsHandWrittenFiles(): void
    {
        // No BOM, bare line feeds, unquoted fields, columns in another order, optional columns left out, blank lines.
        $parsed = NoteExchangeFormat::parseCsv("Content , TITLE,tags\nbody one,First,a;b\n\n\"body, two\",\"Second \"\"quoted\"\"\",\n");

        self::assertSame([], $parsed['errors']);
        self::assertSame(
            [['content' => 'body one', 'title' => 'First', 'tags' => 'a;b'], ['content' => 'body, two', 'title' => 'Second "quoted"', 'tags' => '']],
            array_column($parsed['rows'], 'values'),
        );
        self::assertSame([2, 4], array_column($parsed['rows'], 'line'));
    }

    public function testCsvReportsBrokenLinesAndCarriesOn(): void
    {
        $parsed = NoteExchangeFormat::parseCsv("title,content\nok,fine\nonly one column\na,b,c\nlast,row\n\"never closed,x\n");

        self::assertSame(['ok', 'last'], array_column(array_column($parsed['rows'], 'values'), 'title'));
        self::assertSame(
            [
                ['line' => 3, 'field' => '', 'message' => 'Expected 2 columns, found 1.'],
                ['line' => 4, 'field' => '', 'message' => 'Expected 2 columns, found 3.'],
                ['line' => 6, 'field' => '', 'message' => 'Expected 2 columns, found 1.'],
            ],
            $parsed['errors'],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableCsv(): iterable
    {
        yield 'empty' => ['', 'The file is empty'];
        yield 'only BOM' => ["\xEF\xBB\xBF", 'The file is empty'];
        yield 'no header' => ["First note,its text\n", 'missing: title, content'];
        yield 'missing content column' => ["title,tags\na,b\n", 'missing: content'];
        yield 'unknown column' => ["title,content,owner_id\na,b,c\n", 'Unknown column in the header row: owner_id'];
        yield 'duplicate column' => ["title,content,title\na,b,c\n", 'more than once'];
        yield 'not UTF-8' => ["title,content\n\xFF\xFE,b\n", 'not valid UTF-8'];
    }

    #[DataProvider('unusableCsv')]
    public function testCsvThatCannotBeUsedAtAll(string $content, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        NoteExchangeFormat::parseCsv($content);
    }

    public function testJsonRoundTrip(): void
    {
        $notes = [self::note('a-1', '=not a formula in JSON', "multi\nline ünï 💡", ['a;b', "'x"], 'Projects'), self::note(null, 'Second', 'text', [], null)];
        $json = NoteExchangeFormat::toJson($notes, new \DateTimeImmutable('2026-10-11T12:00:00+03:00'));

        $document = json_decode($json, true);
        self::assertSame(['schema', 'schema_version', 'exported_at', 'count', 'notes'], array_keys($document));
        self::assertSame(['notes-export', 1, '2026-10-11T09:00:00+00:00', 2], [$document['schema'], $document['schema_version'], $document['exported_at'], $document['count']]);
        self::assertStringContainsString('ünï 💡', $json, 'unicode is written as is');

        $parsed = NoteExchangeFormat::parseJson($json);
        self::assertSame([], $parsed['errors']);
        self::assertSame($notes, array_column($parsed['rows'], 'values'));
        self::assertSame([1, 2], array_column($parsed['rows'], 'line'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableJson(): iterable
    {
        yield 'not JSON' => ['{"schema": ', 'not valid JSON'];
        yield 'a list' => ['[{"title": "a"}]', 'must be a JSON object'];
        yield 'no notes' => ['{"schema": "notes-export", "schema_version": 1}', 'must be a JSON object with a "notes" list'];
        yield 'notes not a list' => ['{"schema": "notes-export", "schema_version": 1, "notes": {"a": 1}}', '"notes" list'];
        yield 'wrong schema' => ['{"schema": "something", "schema_version": 1, "notes": []}', '"schema" must be "notes-export"'];
        yield 'missing version' => ['{"schema": "notes-export", "notes": []}', 'Unsupported "schema_version"'];
        yield 'newer version' => ['{"schema": "notes-export", "schema_version": 2, "notes": []}', 'this version reads 1'];
        yield 'version as string' => ['{"schema": "notes-export", "schema_version": "1", "notes": []}', 'Unsupported "schema_version"'];
    }

    #[DataProvider('unusableJson')]
    public function testJsonThatCannotBeUsedAtAll(string $content, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        NoteExchangeFormat::parseJson($content);
    }

    public function testJsonReportsBrokenEntries(): void
    {
        $parsed = NoteExchangeFormat::parseJson('{"schema":"notes-export","schema_version":1,"notes":[{"title":"ok","content":"c"},"text",{"title":"x","content":"c","owner_id":"1"},["a"]]}');

        self::assertCount(1, $parsed['rows']);
        self::assertSame([[2, ''], [3, 'owner_id'], [4, '']], array_map(static fn (array $e): array => [$e['line'], $e['field']], $parsed['errors']));
    }

    public function testTagCell(): void
    {
        self::assertSame([], NoteExchangeFormat::splitTags(''));
        self::assertSame([], NoteExchangeFormat::splitTags(' ; ;'));
        self::assertSame(['a', 'b c'], NoteExchangeFormat::splitTags(' a ; b c ;'));
        self::assertSame(['a;b', 'c\\d'], NoteExchangeFormat::splitTags('a\;b;c\\\\d'));
        self::assertSame('a\;b;c\\\\d', NoteExchangeFormat::joinTags(['a;b', 'c\\d']));
    }

    /**
     * @param list<string> $tags
     *
     * @return array{external_id: string|null, title: string, content: string, tags: list<string>, category: string|null, created_at: string, updated_at: string}
     */
    private static function note(?string $externalId, string $title, string $content, array $tags, ?string $category): array
    {
        return ['external_id' => $externalId, 'title' => $title, 'content' => $content, 'tags' => $tags, 'category' => $category, 'created_at' => '2026-10-11T09:30:00+00:00', 'updated_at' => '2026-10-12T10:00:00+00:00'];
    }
}
