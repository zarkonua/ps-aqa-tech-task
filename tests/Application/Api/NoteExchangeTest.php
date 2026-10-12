<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\Tag;
use App\Service\NoteExchangeFormat;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class NoteExchangeTest extends ApiTestCase
{
    private const SAMPLES = __DIR__.'/../../fixtures/imports/';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temporaryFiles, 'is_file'));

        parent::tearDown();
    }

    public function testCsvExportAndReimportAreEquivalent(): void
    {
        // Notes with everything that is hard for CSV, tagged and categorised.
        $originals = [
            ['title' => 'Quotes "and", commas', 'content' => "Line one\nLine two, with a comma\r\nLine three"],
            ['title' => 'Ünïcödé 日本語 💡 Привет', 'content' => "  spaces kept  \n\ttab"],
            ['title' => '=SUM(1+1)', 'content' => "+cmd|' /C calc'!A0"],
            ['title' => "'apostrophe first", 'content' => '-minus and @at'],
        ];
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/tags', ['name' => 'a;b']));
        $tagA = $this->json()['id'];
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/tags', ['name' => '=tag']));
        $tagB = $this->json()['id'];
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/categories', ['name' => 'Projects']));
        $category = $this->json()['id'];
        foreach ($originals as $index => $note) {
            self::assertSame(201, $this->api('unrelated', 'POST', '/api/notes', $note));
            $id = $this->json()['id'];
            if (0 === $index % 2) {
                self::assertSame(200, $this->api('unrelated', 'PUT', "/api/notes/$id/tags", ['tag_ids' => [$tagA, $tagB]]));
                self::assertSame(200, $this->api('unrelated', 'PUT', "/api/notes/$id/category", ['category_id' => $category]));
            }
        }

        $csv = $this->export('unrelated', 'csv');
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename=notes-\d{8}-\d{6}\.csv$/', (string) $response->headers->get('Content-Disposition'));
        self::assertSame(['4', 'nosniff'], [$response->headers->get('X-Total-Count'), $response->headers->get('X-Content-Type-Options')]);
        self::assertStringStartsWith("\xEF\xBB\xBF\"external_id\",\"title\"", $csv);
        // No cell would be run as a formula by a spreadsheet.
        self::assertStringContainsString('"\'=SUM(1+1)"', $csv);
        self::assertStringContainsString('"\'+cmd|', $csv);
        self::assertStringContainsString('"\'-minus and @at"', $csv);
        self::assertDoesNotMatchRegularExpression('/(^|,)"[=+\-@\t\r]/m', substr($csv, 3));

        // Into another account, and out again.
        self::assertSame(200, $this->import('viewer', $csv, 'export.csv'));
        self::assertSame([4, 4, 0, true], [$this->json()['total_rows'], $this->json()['create'], $this->json()['invalid_rows'], $this->json()['imported']]);

        // Notes made within the same second have no order among themselves, so compare by title.
        $comparable = static function (string $export): array {
            $rows = [];
            foreach (NoteExchangeFormat::parseCsv($export)['rows'] as $row) {
                $rows[$row['values']['title']] = array_diff_key($row['values'], ['created_at' => 1, 'updated_at' => 1]);
            }
            ksort($rows);

            return $rows;
        };
        $reexported = $comparable($this->export('viewer', 'csv'));
        self::assertSame($comparable($csv), $reexported);
        foreach ($originals as $note) {
            self::assertSame($note['content'], $reexported[$note['title']]['content']);
        }
        self::assertSame('=tag;a\;b', $reexported['=SUM(1+1)']['tags']);

        // The tags and the category came along as the importer's own.
        self::assertSame(200, $this->api('viewer', 'GET', '/api/tags'));
        self::assertSame(['=tag', 'a;b'], array_column($this->json()['items'], 'name'));
        self::assertSame(200, $this->api('viewer', 'GET', '/api/categories'));
        self::assertSame(['Projects'], array_column($this->json()['items'], 'name'));
    }

    public function testJsonRoundTripAndSchema(): void
    {
        self::assertSame(200, $this->import('unrelated', (string) file_get_contents(self::SAMPLES.'valid.json'), 'valid.json'));
        self::assertSame([2, 2, 'json'], [$this->json()['total_rows'], $this->json()['create'], $this->json()['format']]);

        $json = $this->export('unrelated', 'json');
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['schema', 'schema_version', 'exported_at', 'count', 'notes'], array_keys($document));
        self::assertSame(['notes-export', 1, 2], [$document['schema'], $document['schema_version'], $document['count']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $document['exported_at']);

        $notes = array_column($document['notes'], null, 'title');
        $note = $notes['From JSON'];
        self::assertSame(['external_id', 'title', 'content', 'tags', 'category', 'created_at', 'updated_at'], array_keys($note));
        self::assertSame(['json-1', 'From JSON', "Line one\nLine two", ['work', 'Ünï'], 'Projects'], [$note['external_id'], $note['title'], $note['content'], $note['tags'], $note['category']]);
        self::assertMatchesRegularExpression('/\+00:00$/', $note['created_at']);
        // The times in the file are not taken over: the note was created now.
        self::assertNotSame('2026-10-01T08:00:00+00:00', $note['created_at']);
        // JSON carries the text as it is.
        self::assertSame('JSON needs no escaping of formulas', $notes['=not a formula here']['content']);

        // What was exported can be imported as is.
        self::assertSame(200, $this->import('viewer', $json, 'again.json'));
        self::assertSame(2, $this->json()['create']);
    }

    public function testValidateShowsWhatWouldHappenAndChangesNothing(): void
    {
        self::assertSame(200, $this->import('unrelated', (string) file_get_contents(self::SAMPLES.'notes.csv'), 'notes.csv', [], true));
        $preview = $this->json();

        self::assertSame(
            ['format', 'mode', 'duplicates', 'total_rows', 'valid_rows', 'invalid_rows', 'create', 'update', 'skip', 'importable', 'errors', 'preview'],
            array_keys($preview),
        );
        self::assertSame(['csv', 'atomic', 'reject', 3, 3, 0, 3, 0, 0, true, []], array_slice(array_values($preview), 0, 11));
        self::assertSame(
            ['line' => 3, 'action' => 'create', 'external_id' => 'imp-2', 'title' => 'Quotes "and", commas', 'content_length' => 47, 'tags' => [], 'category' => null],
            $preview['preview'][1],
        );
        self::assertSame([2, 3, 6], array_column($preview['preview'], 'line'), 'a row spanning lines counts from where it starts');
        self::assertSame(['reading'], $preview['preview'][2]['tags']);

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes'));
        self::assertSame([], $this->json());
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/tags'));
        self::assertSame(0, $this->json()['total']);
    }

    public function testInvalidRowBlocksAtomicImportAndIsLeftOutOfAPartialOne(): void
    {
        $file = (string) file_get_contents(self::SAMPLES.'invalid-rows.csv');
        $expectedErrors = [
            ['line' => 3, 'field' => 'title', 'message' => 'Title is required.'],
            ['line' => 4, 'field' => 'external_id', 'message' => 'External ID must be 1 to 64 letters, digits, dots, dashes, underscores or colons, starting with a letter or digit.'],
            ['line' => 5, 'field' => 'tags', 'message' => 'A note can have at most 10 tags.'],
        ];

        self::assertSame(200, $this->import('unrelated', $file, 'rows.csv', [], true));
        self::assertSame([5, 2, 3, false, $expectedErrors], [$this->json()['total_rows'], $this->json()['valid_rows'], $this->json()['invalid_rows'], $this->json()['importable'], $this->json()['errors']]);

        // Atomic (the default): all or nothing.
        self::assertSame(422, $this->import('unrelated', $file, 'rows.csv'));
        self::assertSame(['The file has invalid rows; nothing was imported.', false, $expectedErrors], [$this->json()['error'], $this->json()['imported'], $this->json()['errors']]);
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes'));
        self::assertSame([], $this->json(), 'a bad import changes nothing');
        self::assertSame(0, $this->em()->getRepository(Tag::class)->count(['owner' => $this->userIds['unrelated']]));
        self::assertSame(0, $this->em()->getRepository(AuditEvent::class)->count(['actorId' => $this->userIds['unrelated']]));

        // Partial: the good rows go in, the bad ones are reported.
        self::assertSame(200, $this->import('unrelated', $file, 'rows.csv', ['mode' => 'partial'], true));
        self::assertTrue($this->json()['importable']);
        self::assertSame(200, $this->import('unrelated', $file, 'rows.csv', ['mode' => 'partial']));
        $result = $this->json();
        self::assertSame([2, 3, true, $expectedErrors], [$result['create'], $result['invalid_rows'], $result['imported'], $result['errors']]);
        self::assertSame([[2, 'created'], [6, 'created']], array_map(static fn (array $n): array => [$n['line'], $n['action']], $result['notes']));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes?sortBy=title'));
        self::assertSame(['Another good row', 'Good row'], array_column($this->json(), 'title'));
        self::assertSame(['ok-5', 'ok-1'], array_column($this->json(), 'external_id'));
    }

    public function testDuplicatePolicies(): void
    {
        $first = "external_id,title,content,tags\nx-1,Original one,text one,keep\nx-2,Original two,text two,\n";
        self::assertSame(200, $this->import('unrelated', $first, 'first.csv'));
        $ids = array_column($this->json()['notes'], 'id');
        $second = "external_id,title,content,tags\nx-1,Changed one,text one changed,new\nx-3,Brand new,text three,\n,No external id,text four,\n";

        // reject (default): the row with a known ID is an error, so an atomic import is refused.
        self::assertSame(422, $this->import('unrelated', $second, 'second.csv'));
        self::assertSame([['line' => 2, 'field' => 'external_id', 'message' => 'You already have a note with the external ID "x-1".']], $this->json()['errors']);

        // skip: left as it is.
        self::assertSame(200, $this->import('unrelated', $second, 'second.csv', ['duplicates' => 'skip']));
        self::assertSame([2, 0, 1], [$this->json()['create'], $this->json()['update'], $this->json()['skip']]);
        self::assertSame([[2, 'skipped', $ids[0]], [3, 'created'], [4, 'created']], array_map(static fn (array $n): array => 'skipped' === $n['action'] ? [$n['line'], $n['action'], $n['id']] : [$n['line'], $n['action']], $this->json()['notes']));
        self::assertSame('Original one', $this->em()->find(Note::class, $ids[0])->getTitle());

        // upsert: updated like an edit, with a revision and an audit record. x-3 now exists too.
        self::assertSame(200, $this->import('unrelated', $second, 'second.csv', ['duplicates' => 'upsert'], true));
        self::assertSame([1, 2, 0], [$this->json()['create'], $this->json()['update'], $this->json()['skip']]);
        self::assertSame(200, $this->import('unrelated', $second, 'second.csv', ['duplicates' => 'upsert']));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes/'.$ids[0]));
        self::assertSame(['Changed one', 'text one changed', 2, ['new'], 'x-1'], [$this->json()['title'], $this->json()['content'], $this->json()['version'], array_column($this->json()['tags'], 'name'), $this->json()['external_id']]);
        self::assertSame(2, $this->em()->getRepository(NoteRevision::class)->count(['note' => $ids[0]]));
        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['noteId' => $ids[0], 'eventType' => AuditEvent::NOTE_UPDATED]));

        // The same file again changes nothing more for the rows with IDs; a row without one is always new.
        self::assertSame(200, $this->import('unrelated', $second, 'second.csv', ['duplicates' => 'upsert']));
        self::assertSame(2, $this->em()->find(Note::class, $ids[0])->getVersion());
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes?limit=100'));
        self::assertEquals(['Brand new' => 1, 'Changed one' => 1, 'No external id' => 3, 'Original two' => 1], array_count_values(array_column($this->json(), 'title')));

        // The same ID twice in one file is an error whatever the policy.
        self::assertSame(422, $this->import('unrelated', (string) file_get_contents(self::SAMPLES.'duplicates.csv'), 'duplicates.csv', ['duplicates' => 'upsert']));
        self::assertSame([['line' => 4, 'field' => 'external_id', 'message' => 'External ID "dup-1" is already used on line 2 of this file.']], $this->json()['errors']);
    }

    public function testImportedNotesBelongToTheImporter(): void
    {
        self::assertSame(200, $this->import('unrelated', "external_id,title,content,tags,category\nshared-id,Mine,text,Work,Projects\n", 'a.csv'));
        $id = $this->json()['notes'][0]['id'];
        self::assertSame($this->userIds['unrelated'], $this->em()->find(Note::class, $id)->getOwner()->getId());

        // External IDs are per owner: the same one in another account is another note, not a conflict or an update.
        self::assertSame(200, $this->import('viewer', "external_id,title,content\nshared-id,Not yours,other\n", 'b.csv', ['duplicates' => 'upsert']));
        self::assertSame([1, 0], [$this->json()['create'], $this->json()['update']]);
        self::assertSame('Mine', $this->em()->find(Note::class, $id)->getTitle());
        foreach (['owner', 'viewer', 'editor'] as $persona) {
            self::assertSame(404, $this->api($persona, 'GET', '/api/notes/'.$id), $persona);
        }

        // Existing tags are reused whatever their case; nothing is created in anybody else's taxonomy.
        self::assertSame(200, $this->import('unrelated', "title,content,tags,category\nSecond,text,WORK;extra,projects\n", 'c.csv'));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/tags'));
        self::assertSame(['extra', 'Work'], array_column($this->json()['items'], 'name'));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/categories'));
        self::assertSame(['Projects'], array_column($this->json()['items'], 'name'));
        self::assertSame(200, $this->api('owner', 'GET', '/api/tags'));
        self::assertSame(0, $this->json()['total']);
    }

    public function testExportOnlyHoldsWhatTheCallerMaySee(): void
    {
        $titles = function (string $persona, string $query = ''): array {
            $titles = array_column(array_column(NoteExchangeFormat::parseCsv($this->export($persona, 'csv', $query))['rows'], 'values'), 'title');
            sort($titles);

            return $titles;
        };
        $run = $this->runId;

        self::assertSame(["$run private_1", "$run private_2", "$run shared_1", "$run shared_2"], $titles('owner'));
        // By default only one's own notes; shared ones on request; never anything else.
        self::assertSame([], $titles('viewer'));
        self::assertSame(["$run shared_1", "$run shared_2"], $titles('viewer', '&scope=shared'));
        self::assertSame(["$run shared_1"], $titles('editor', '&scope=all'));
        self::assertSame([], $titles('unrelated', '&scope=all'));
        self::assertSame("\xEF\xBB\xBF\"external_id\",\"title\",\"content\",\"tags\",\"category\",\"created_at\",\"updated_at\"\r\n", $this->export('unrelated', 'csv'));

        // A revoked share is out of the next export.
        self::assertSame(204, $this->api('owner', 'DELETE', $this->sharesUri('shared_1', 'shared_1/viewer')));
        self::assertSame(["$run shared_2"], $titles('viewer', '&scope=all'));

        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['actorId' => $this->userIds['editor'], 'eventType' => AuditEvent::NOTES_EXPORTED]));
        foreach (['', 'format=xml', 'format=CSV', 'format[]=csv', 'format=csv&scope=mine'] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', '/api/exports/notes?'.$query), $query);
        }
        self::assertSame(401, $this->api(null, 'GET', '/api/exports/notes?format=csv'));
        self::assertSame(401, $this->api(null, 'GET', '/api/exports/notes?format=json', null, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]));
    }

    public function testMaliciousInputIsStoredAsTextAndNeutralizedOnExport(): void
    {
        self::assertSame(200, $this->import('unrelated', (string) file_get_contents(self::SAMPLES.'malicious.csv'), 'malicious.csv'));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes?sortBy=createdAt&order=asc'));
        self::assertEquals(
            ['=HYPERLINK("http://evil.example","click")' => "+cmd|' /C calc'!A0", '<script>alert(1)</script>' => "'; DROP TABLE notes; --"],
            array_column($this->json(), 'content', 'title'),
        );

        $csv = $this->export('unrelated', 'csv');
        self::assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""click"")"', $csv);
        self::assertStringContainsString('"\'-tag;@other"', $csv);
        self::assertStringContainsString('"\'\'; DROP TABLE notes; --"', $csv);
        self::assertDoesNotMatchRegularExpression('/(^|,)"[=+\-@]/m', substr($csv, 3));
    }

    public function testFilesThatCannotBeUsedAtAll(): void
    {
        $cases = [
            'missing header' => [(string) file_get_contents(self::SAMPLES.'missing-header.csv'), 'a.csv', 'missing: title, content'],
            'unknown column' => ["title,content,owner_id\na,b,c\n", 'a.csv', 'Unknown column'],
            'empty file' => ['', 'a.csv', 'The file is empty'],
            'header only' => ["title,content\n", 'a.csv', 'no rows to import'],
            'not UTF-8' => ["title,content\n\xFF\xFE,x\n", 'a.csv', 'not valid UTF-8'],
            'broken JSON' => ['{"schema":', 'a.json', 'not valid JSON'],
            'JSON list' => ['[]', 'a.json', '"notes" list'],
            'newer schema' => [(string) file_get_contents(self::SAMPLES.'unsupported-version.json'), 'a.json', 'Unsupported "schema_version"'],
            'JSON named csv' => ['{"schema":"notes-export","schema_version":1,"notes":[]}', 'a.csv', 'header row must contain'],
            'other file type' => ["title,content\na,b\n", 'notes.xlsx', 'must be .csv or .json'],
        ];
        foreach ($cases as $name => [$content, $filename, $expected]) {
            foreach ([true, false] as $validateOnly) {
                self::assertSame(422, $this->import('unrelated', $content, $filename, [], $validateOnly), $name);
                self::assertStringContainsString($expected, $this->json()['violations'][0]['message'], $name);
            }
        }
        // The format can be named when the file name does not say it.
        self::assertSame(200, $this->import('unrelated', "title,content\na,b\n", 'upload.tmp', ['format' => 'csv'], true));

        $invalidJson = (string) file_get_contents(self::SAMPLES.'invalid.json');
        self::assertSame(422, $this->import('unrelated', $invalidJson, 'invalid.json'));
        self::assertSame([[2, 'title'], [3, 'owner_id'], [4, '']], array_map(static fn (array $e): array => [$e['line'], $e['field']], $this->json()['errors']));

        foreach ([['mode' => 'fast'], ['duplicates' => 'overwrite'], ['mode' => ''], ['owner_id' => $this->userIds['owner']]] as $options) {
            self::assertSame(422, $this->import('unrelated', "title,content\na,b\n", 'a.csv', $options), json_encode($options));
        }
        $this->client->request('POST', '/api/imports/notes', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token('unrelated'), 'HTTP_ACCEPT' => 'application/json']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('file', $this->json()['violations'][0]['propertyPath']);
        self::assertSame(401, $this->import(null, "title,content\na,b\n", 'a.csv'));

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes'));
        self::assertSame([], $this->json());
    }

    public function testRowAndSizeLimits(): void
    {
        $rows = static fn (int $count): string => "title,content\n".implode('', array_map(static fn (int $i): string => "Note $i,text $i\n", range(1, $count)));

        self::assertSame(200, $this->import('unrelated', $rows(1000), 'max.csv', [], true));
        self::assertSame([1000, 1000, true], [$this->json()['total_rows'], $this->json()['create'], $this->json()['importable']]);
        self::assertCount(20, $this->json()['preview']);

        self::assertSame(422, $this->import('unrelated', $rows(1001), 'over.csv', [], true));
        self::assertSame('The file has 1001 rows; at most 1000 can be imported at once.', $this->json()['violations'][0]['message']);
        self::assertSame(422, $this->import('unrelated', $rows(1001), 'over.csv', ['mode' => 'partial']));

        $big = "title,content\nBig,".str_repeat('x', 5 * 1024 * 1024)."\n";
        self::assertSame(413, $this->import('unrelated', $big, 'big.csv'));
        self::assertSame(['error' => 'The file is larger than 5 MiB.'], $this->json());
        self::assertSame(413, $this->import('unrelated', $big, 'big.csv', [], true));

        // Field limits are those of a note.
        $long = "title,content\n".str_repeat('t', 256).",x\nok,".str_repeat('c', 10001)."\n".str_repeat('t', 255).','.str_repeat('c', 10000)."\n";
        self::assertSame(200, $this->import('unrelated', $long, 'long.csv', ['mode' => 'partial'], true));
        self::assertSame([[2, 'title'], [3, 'content']], array_map(static fn (array $e): array => [$e['line'], $e['field']], $this->json()['errors']));
        self::assertSame(1, $this->json()['valid_rows']);

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes'));
        self::assertSame([], $this->json());
    }

    public function testImportsAreAuditedAndThrottled(): void
    {
        self::assertSame(200, $this->import('unrelated', "title,content\na,b\nc,d\n", 'a.csv', [], false, ['X-Correlation-ID' => 'import-test-01']));
        $event = $this->em()->getRepository(AuditEvent::class)->findOneBy(['actorId' => $this->userIds['unrelated'], 'eventType' => AuditEvent::NOTES_IMPORTED]);
        self::assertEquals(['format' => 'csv', 'mode' => 'atomic', 'duplicates' => 'reject', 'created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0], $event->getMetadata());
        self::assertSame('import-test-01', $event->getCorrelationId());
        self::assertSame(2, $this->em()->getRepository(AuditEvent::class)->count(['actorId' => $this->userIds['unrelated'], 'eventType' => AuditEvent::NOTE_CREATED]));

        $statuses = [];
        for ($i = 0; $i < 20; ++$i) {
            $statuses[] = $this->import('unrelated', "title,content\na,b\n", 'a.csv', [], true);
        }
        self::assertSame([200 => 19, 429 => 1], array_count_values($statuses));
        self::assertSame(200, $this->import('viewer', "title,content\na,b\n", 'a.csv', [], true));
    }

    public function testOpenApiDocumentsExchangeRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        self::assertEqualsCanonicalizing(['200', '401', '413', '422', '429', '500'], array_map('strval', array_keys($paths['/api/imports/notes']['post']['responses'])));
        self::assertArrayHasKey('multipart/form-data', $paths['/api/imports/notes/validate']['post']['requestBody']['content']);
        self::assertEqualsCanonicalizing(['text/csv', 'application/json'], array_keys($paths['/api/exports/notes']['get']['responses']['200']['content']));
        self::assertArrayHasKey('external_id', $this->json()['components']['schemas']['Note-note.read']['properties']);
    }

    private function export(string $persona, string $format, string $query = ''): string
    {
        self::assertSame(200, $this->api($persona, 'GET', '/api/exports/notes?format='.$format.$query));

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array<string, string> $options
     * @param array<string, string> $headers
     */
    private function import(?string $persona, string $content, string $filename, array $options = [], bool $validateOnly = false, array $headers = []): int
    {
        $this->temporaryFiles[] = $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $content);

        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $persona) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token($persona);
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request('POST', '/api/imports/notes'.($validateOnly ? '/validate' : ''), $options, ['file' => new UploadedFile($path, $filename, null, null, true)], $server);
        $this->em()->clear();

        return $this->client->getResponse()->getStatusCode();
    }
}
