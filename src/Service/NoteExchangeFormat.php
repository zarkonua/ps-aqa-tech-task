<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The CSV and JSON shapes notes are exported in and imported from. No database, no permissions:
 * text in, rows out, and back.
 */
final class NoteExchangeFormat
{
    public const SCHEMA = 'notes-export';
    public const SCHEMA_VERSION = 1;
    public const COLUMNS = ['external_id', 'title', 'content', 'tags', 'category', 'created_at', 'updated_at'];
    public const REQUIRED_COLUMNS = ['title', 'content'];

    private const BOM = "\xEF\xBB\xBF";
    /** A cell starting with one of these is run as a formula by spreadsheet programs. */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<array{external_id: string|null, title: string, content: string, tags: list<string>, category: string|null, created_at: string, updated_at: string}> $notes
     */
    public static function toCsv(array $notes): string
    {
        $lines = [self::csvLine(self::COLUMNS)];
        foreach ($notes as $note) {
            $lines[] = self::csvLine([
                self::neutralize((string) $note['external_id']),
                self::neutralize($note['title']),
                self::neutralize($note['content']),
                self::neutralize(self::joinTags($note['tags'])),
                self::neutralize((string) $note['category']),
                $note['created_at'],
                $note['updated_at'],
            ]);
        }

        // The byte order mark makes spreadsheet programs read the file as UTF-8.
        return self::BOM.implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param list<array<string, mixed>> $notes
     */
    public static function toJson(array $notes, \DateTimeImmutable $exportedAt): string
    {
        return json_encode([
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => $exportedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'count' => count($notes),
            'notes' => $notes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Reads a CSV file into rows keyed by column name.
     *
     * @return array{rows: list<array{line: int, values: array<string, string>}>, errors: list<array{line: int, field: string, message: string}>}
     *
     * @throws \InvalidArgumentException when the file as a whole cannot be used (no header, bad columns, bad encoding)
     */
    public static function parseCsv(string $content): array
    {
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \InvalidArgumentException('The file is not valid UTF-8.');
        }

        $records = self::csvRecords($content);
        $header = array_shift($records);
        if (null === $header) {
            throw new \InvalidArgumentException('The file is empty: a header row with at least "title" and "content" is required.');
        }

        $columns = array_map(static fn (string $name): string => strtolower(trim($name)), $header['fields']);
        $missing = array_diff(self::REQUIRED_COLUMNS, $columns);
        if ([] !== $missing) {
            throw new \InvalidArgumentException(sprintf('The header row must contain the columns "title" and "content"; missing: %s.', implode(', ', $missing)));
        }
        $unknown = array_diff($columns, self::COLUMNS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown column in the header row: %s. Allowed: %s.', mb_substr(implode(', ', $unknown), 0, 100), implode(', ', self::COLUMNS)));
        }
        if (count(array_unique($columns)) !== count($columns)) {
            throw new \InvalidArgumentException('A column appears more than once in the header row.');
        }

        $rows = [];
        $errors = [];
        foreach ($records as $record) {
            // A line with nothing on it is not a row.
            if ([''] === $record['fields']) {
                continue;
            }
            if (count($record['fields']) !== count($columns)) {
                $errors[] = ['line' => $record['line'], 'field' => '', 'message' => sprintf('Expected %d columns, found %d.', count($columns), count($record['fields']))];
                continue;
            }
            if ($record['unterminated']) {
                $errors[] = ['line' => $record['line'], 'field' => '', 'message' => 'A quoted value is not closed.'];
                continue;
            }
            $rows[] = ['line' => $record['line'], 'values' => array_map(self::denormalize(...), array_combine($columns, $record['fields']))];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Reads a JSON export into the same row shape as CSV.
     *
     * @return array{rows: list<array{line: int, values: array<string, mixed>}>, errors: list<array{line: int, field: string, message: string}>}
     *
     * @throws \InvalidArgumentException when the document is not an export this version understands
     */
    public static function parseJson(string $content): array
    {
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, 3);
        }

        try {
            $document = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The file is not valid JSON.');
        }
        if (!is_array($document) || array_is_list($document) || !is_array($document['notes'] ?? null) || !array_is_list($document['notes'])) {
            throw new \InvalidArgumentException('The file must be a JSON object with a "notes" list.');
        }
        if (self::SCHEMA !== ($document['schema'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('"schema" must be "%s".', self::SCHEMA));
        }
        if (self::SCHEMA_VERSION !== ($document['schema_version'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('Unsupported "schema_version": this version reads %d.', self::SCHEMA_VERSION));
        }

        $rows = [];
        $errors = [];
        foreach ($document['notes'] as $index => $note) {
            $line = $index + 1;
            if (!is_array($note) || ([] !== $note && array_is_list($note))) {
                $errors[] = ['line' => $line, 'field' => '', 'message' => 'Each note must be an object.'];
                continue;
            }
            $unknown = array_diff(array_keys($note), self::COLUMNS);
            if ([] !== $unknown) {
                $errors[] = ['line' => $line, 'field' => mb_substr((string) reset($unknown), 0, 64), 'message' => 'Unexpected field.'];
                continue;
            }
            $rows[] = ['line' => $line, 'values' => $note];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param list<string> $tags
     */
    public static function joinTags(array $tags): string
    {
        return implode(';', array_map(static fn (string $tag): string => str_replace(['\\', ';'], ['\\\\', '\;'], $tag), $tags));
    }

    /**
     * @return list<string>
     */
    public static function splitTags(string $cell): array
    {
        if ('' === trim($cell)) {
            return [];
        }

        $tags = [];
        $current = '';
        $length = strlen($cell);
        for ($i = 0; $i < $length; ++$i) {
            if ('\\' === $cell[$i] && $i + 1 < $length) {
                $current .= $cell[++$i];
            } elseif (';' === $cell[$i]) {
                $tags[] = $current;
                $current = '';
            } else {
                $current .= $cell[$i];
            }
        }
        $tags[] = $current;

        return array_values(array_filter(array_map('trim', $tags), static fn (string $tag): bool => '' !== $tag));
    }

    /**
     * Makes a value safe to open in a spreadsheet: one that would be read as a formula gets a leading
     * apostrophe, the spreadsheet convention for "this is text". Values that already start with an
     * apostrophe get one more, so that importing can undo exactly this.
     */
    public static function neutralize(string $value): string
    {
        return '' !== $value && (in_array($value[0], self::FORMULA_START, true) || "'" === $value[0]) ? "'".$value : $value;
    }

    /**
     * Undoes neutralize(): drops a leading apostrophe that stands in front of what it protects against
     * (or in front of another apostrophe). Any other leading apostrophe is part of the text.
     */
    public static function denormalize(string $value): string
    {
        $second = $value[1] ?? '';

        return str_starts_with($value, "'") && ("'" === $second || in_array($second, self::FORMULA_START, true)) ? substr($value, 1) : $value;
    }

    /**
     * @param list<string> $fields
     */
    private static function csvLine(array $fields): string
    {
        return implode(',', array_map(static fn (string $field): string => '"'.str_replace('"', '""', $field).'"', $fields));
    }

    /**
     * Splits CSV text into records (RFC 4180): commas separate fields, quotes wrap fields that contain
     * commas, quotes or line breaks, and a quote inside a quoted field is doubled.
     *
     * @return list<array{line: int, fields: list<string>, unterminated: bool}>
     */
    private static function csvRecords(string $content): array
    {
        $records = [];
        $fields = [];
        $field = '';
        $quoted = false;
        $line = 1;
        $recordLine = 1;
        $started = false;
        $length = strlen($content);

        for ($i = 0; $i < $length; ++$i) {
            $char = $content[$i];
            if ($quoted) {
                if ('"' === $char) {
                    if ('"' === ($content[$i + 1] ?? '')) {
                        $field .= '"';
                        ++$i;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $char;
                    $line += "\n" === $char ? 1 : 0;
                }
                continue;
            }

            if ('"' === $char && '' === $field) {
                $quoted = true;
                $started = true;
            } elseif (',' === $char) {
                $fields[] = $field;
                $field = '';
                $started = true;
            } elseif ("\n" === $char || "\r" === $char) {
                if ("\r" === $char && "\n" === ($content[$i + 1] ?? '')) {
                    ++$i;
                }
                $fields[] = $field;
                $records[] = ['line' => $recordLine, 'fields' => $fields, 'unterminated' => false];
                $fields = [];
                $field = '';
                $started = false;
                $recordLine = ++$line;
            } else {
                $field .= $char;
                $started = true;
            }
        }
        if ($started || [] !== $fields || '' !== $field) {
            $fields[] = $field;
            $records[] = ['line' => $recordLine, 'fields' => $fields, 'unterminated' => $quoted];
        }

        return $records;
    }
}
