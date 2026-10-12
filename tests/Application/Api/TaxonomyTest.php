<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\Category;
use App\Entity\Note;
use App\Entity\Tag;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;

final class TaxonomyTest extends ApiTestCase
{
    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function kinds(): iterable
    {
        yield 'tags' => ['/api/tags', Tag::class, 'tag'];
        yield 'categories' => ['/api/categories', Category::class, 'category'];
    }

    #[DataProvider('kinds')]
    public function testCrudPersists(string $uri, string $class, string $label): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $uri, ['name' => "  Work \t stuff ", 'color' => '#1F77B4'], ['X-Correlation-ID' => 'taxonomy-test-1']));
        $item = $this->json();
        self::assertSame(['id', 'name', 'color', 'version', 'created_at', 'updated_at'], array_keys($item));
        self::assertSame('Work stuff', $item['name']);
        self::assertSame('#1f77b4', $item['color']);
        self::assertSame(1, $item['version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $item['created_at']);

        self::assertSame(201, $this->api('owner', 'POST', $uri, ['name' => 'Alpha']));
        self::assertNull($this->json()['color']);

        self::assertSame(200, $this->api('owner', 'GET', $uri));
        $list = $this->json();
        self::assertSame(['Alpha', 'Work stuff'], array_column($list['items'], 'name'));
        self::assertSame(['total' => 2, 'page' => 1, 'items_per_page' => 50], array_diff_key($list, ['items' => 1]));

        self::assertSame(200, $this->api('owner', 'PATCH', $uri.'/'.$item['id'], ['name' => 'Renamed', 'color' => null, 'version' => 1]));
        self::assertSame(['Renamed', null, 2], [$this->json()['name'], $this->json()['color'], $this->json()['version']]);

        // Nothing to change: same version, no audit record.
        self::assertSame(200, $this->api('owner', 'PATCH', $uri.'/'.$item['id'], ['name' => ' Renamed ']));
        self::assertSame(2, $this->json()['version']);

        self::assertSame(204, $this->api('owner', 'DELETE', $uri.'/'.$item['id']));
        self::assertSame(404, $this->api('owner', 'DELETE', $uri.'/'.$item['id']));
        self::assertSame(200, $this->api('owner', 'GET', $uri));
        self::assertSame(['Alpha'], array_column($this->json()['items'], 'name'));

        $events = $this->em()->getRepository(AuditEvent::class)->findBy(['subjectId' => $item['id']]);
        $types = array_map(static fn (AuditEvent $e): string => $e->getEventType(), $events);
        sort($types);
        self::assertSame([$label.'.created', $label.'.deleted', $label.'.updated'], $types);
        self::assertSame($this->userIds['owner'], $events[0]->getActorId());
        self::assertContains('taxonomy-test-1', array_map(static fn (AuditEvent $e): ?string => $e->getCorrelationId(), $events));
    }

    #[DataProvider('kinds')]
    public function testNamesAreUniquePerUserIgnoringCase(string $uri, string $class, string $label): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $uri, ['name' => 'Über Café']));
        $id = $this->json()['id'];

        foreach (['ÜBER CAFÉ', 'über café', "  Über   Café\n", "U\u{0308}ber Cafe\u{0301}"] as $duplicate) {
            self::assertSame(409, $this->api('owner', 'POST', $uri, ['name' => $duplicate]), $duplicate);
        }
        // Accents matter, only case does not.
        self::assertSame(201, $this->api('owner', 'POST', $uri, ['name' => 'Uber Cafe']));
        $otherId = $this->json()['id'];

        // Another user may use the same name.
        self::assertSame(201, $this->api('editor', 'POST', $uri, ['name' => 'ÜBER CAFÉ']));

        // Changing only the case of one's own name is fine; taking another item's name is not.
        self::assertSame(200, $this->api('owner', 'PATCH', $uri.'/'.$id, ['name' => 'ÜBER café']));
        self::assertSame('ÜBER café', $this->json()['name']);
        self::assertSame(409, $this->api('owner', 'PATCH', $uri.'/'.$otherId, ['name' => 'über CAFÉ']));

        self::assertSame(200, $this->api('owner', 'GET', $uri.'?q=CAF'));
        self::assertSame(2, $this->json()['total']);
        self::assertSame(200, $this->api('owner', 'GET', $uri.'?q=%25'));
        self::assertSame(0, $this->json()['total']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int, string|null}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'malformed JSON' => ['{"name": ', 400, null];
        yield 'JSON list' => ['["Work"]', 400, null];
        yield 'empty body' => ['', 422, 'name'];
        yield 'missing name' => [['color' => '#ffffff'], 422, 'name'];
        yield 'null name' => [['name' => null], 422, 'name'];
        yield 'empty name' => [['name' => ''], 422, 'name'];
        yield 'blank name' => [['name' => " \t\n "], 422, 'name'];
        yield 'name type mismatch' => [['name' => 42], 422, 'name'];
        yield 'name as list' => [['name' => ['Work']], 422, 'name'];
        yield '31 characters' => [['name' => str_repeat('a', 31)], 422, 'name'];
        yield '31 multibyte characters' => [['name' => str_repeat('é', 31)], 422, 'name'];
        yield 'color name' => [['name' => 'Work', 'color' => 'red'], 422, 'color'];
        yield 'short hex' => [['name' => 'Work', 'color' => '#fff'], 422, 'color'];
        yield 'hex without hash' => [['name' => 'Work', 'color' => '1f77b4'], 422, 'color'];
        yield 'color type mismatch' => [['name' => 'Work', 'color' => 255], 422, 'color'];
        yield 'color with trailing newline' => [['name' => 'Work', 'color' => "#1f77b4\n"], 422, 'color'];
        yield 'unexpected field' => [['name' => 'Work', 'owner_id' => 'x'], 422, 'owner_id'];
    }

    #[DataProvider('invalidPayloads')]
    public function testCreateValidation(array|string $body, int $expectedStatus, ?string $expectedField): void
    {
        foreach (['/api/tags', '/api/categories'] as $uri) {
            self::assertSame($expectedStatus, $this->api('owner', 'POST', $uri, $body));
            if (null !== $expectedField) {
                self::assertContains($expectedField, array_column($this->json()['violations'], 'propertyPath'));
            }
            self::assertSame(200, $this->api('owner', 'GET', $uri));
            self::assertSame(0, $this->json()['total']);
        }
    }

    public function testNameLengthBoundariesAndUnsafeText(): void
    {
        foreach (['a', str_repeat('b', 30), str_repeat('日', 30), '<script>alert(1)</script>', "Robert'); DROP TABLE--", '💡 idea'] as $name) {
            self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => $name]), $name);
            self::assertSame($name, $this->json()['name']);
        }
        // 30 characters once the surrounding whitespace is trimmed.
        self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => '  '.str_repeat('c', 30).'  ']));
    }

    #[DataProvider('kinds')]
    public function testUpdateValidationAndConflicts(string $uri, string $class, string $label): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $uri, ['name' => 'Work']));
        $itemUri = $uri.'/'.$this->json()['id'];

        $invalid = [
            [[], 422], [['version' => 1], 422], [['name' => ''], 422], [['name' => null], 422], [['name' => str_repeat('a', 31)], 422],
            [['color' => 'blue'], 422], [['name' => 'x', 'version' => '1'], 422], [['name' => 'x', 'version' => 0], 422],
            [['name' => 'x', 'id' => 'y'], 422], ['{bad', 400],
        ];
        foreach ($invalid as [$body, $status]) {
            self::assertSame($status, $this->api('owner', 'PATCH', $itemUri, $body), json_encode($body));
        }

        self::assertSame(200, $this->api('owner', 'PATCH', $itemUri, ['color' => '#000000', 'version' => 1]));
        // A second client still holding version 1.
        self::assertSame(409, $this->api('owner', 'PATCH', $itemUri, ['name' => 'Stale', 'version' => 1]));
        self::assertSame(200, $this->api('owner', 'GET', $uri));
        self::assertSame(['Work', '#000000', 2], [$this->json()['items'][0]['name'], $this->json()['items'][0]['color'], $this->json()['items'][0]['version']]);
    }

    #[DataProvider('kinds')]
    public function testTaxonomyIsPrivateToItsOwner(string $uri, string $class, string $label): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $uri, ['name' => 'Mine']));
        $itemUri = $uri.'/'.$this->json()['id'];

        // Not even an editor of the owner's notes can see or change the owner's taxonomy.
        foreach (['editor', 'viewer', 'unrelated'] as $persona) {
            self::assertSame(200, $this->api($persona, 'GET', $uri));
            self::assertSame(0, $this->json()['total'], $persona);
            self::assertSame(404, $this->api($persona, 'PATCH', $itemUri, ['name' => 'Taken over']), $persona);
            self::assertSame(404, $this->api($persona, 'DELETE', $itemUri), $persona);
        }

        foreach (['GET', 'POST'] as $method) {
            self::assertSame(401, $this->api(null, $method, $uri, ['name' => 'x']));
        }
        self::assertSame(401, $this->api(null, 'PATCH', $itemUri, ['name' => 'x']));
        self::assertSame(401, $this->api(null, 'DELETE', $itemUri, null, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]));
        self::assertSame(404, $this->api('owner', 'PATCH', $uri.'/not-a-uuid', ['name' => 'x']));
        self::assertSame(404, $this->api('owner', 'DELETE', $uri.'/00000000-0000-4000-8000-000000000000'));

        self::assertSame('Mine', $this->em()->getRepository($class)->findOneBy(['owner' => $this->userIds['owner']])->getName());
    }

    public function testListPaginationAndLimit(): void
    {
        $em = $this->em();
        $owner = $em->find(User::class, $this->userIds['owner']);
        for ($i = 1; $i <= 100; ++$i) {
            $em->persist(new Tag($owner, sprintf('tag %03d', $i)));
        }
        $em->flush();

        self::assertSame(409, $this->api('owner', 'POST', '/api/tags', ['name' => 'one too many']));
        self::assertSame(['error' => 'Tag limit reached (100).'], $this->json());

        self::assertSame(200, $this->api('owner', 'GET', '/api/tags?page=2&itemsPerPage=30'));
        self::assertSame(['tag 031', 100], [$this->json()['items'][0]['name'], $this->json()['total']]);
        self::assertCount(30, $this->json()['items']);

        foreach (['page=0', 'itemsPerPage=101', 'itemsPerPage=x', 'q[]=a', 'q='.str_repeat('a', 31)] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', '/api/tags?'.$query), $query);
        }
    }

    public function testNoteGetsTagsAndCategory(): void
    {
        $tags = $this->createItems('owner', '/api/tags', ['Zeta', 'alpha', 'Beta']);
        $category = $this->createItems('owner', '/api/categories', ['Projects'])['Projects'];

        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('private_1')));
        self::assertSame([null, []], [$this->json()['category'], $this->json()['tags']]);

        self::assertSame(200, $this->api('owner', 'PUT', $this->noteUri('private_1').'/tags', ['tag_ids' => array_values($tags)]));
        self::assertSame(['note_id', 'category', 'tags'], array_keys($this->json()));
        self::assertSame(['alpha', 'Beta', 'Zeta'], array_column($this->json()['tags'], 'name'));
        self::assertSame(200, $this->api('owner', 'PUT', $this->noteUri('private_1').'/category', ['category_id' => $category]));
        self::assertSame(['id' => $category, 'name' => 'Projects', 'color' => null], $this->json()['category']);

        // Associations persist and are part of the note, in the list as well.
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('private_1')));
        self::assertSame(['alpha', 'Beta', 'Zeta'], array_column($this->json()['tags'], 'name'));
        self::assertSame(['id', 'name', 'color'], array_keys($this->json()['tags'][0]));
        self::assertSame('Projects', $this->json()['category']['name']);
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?itemsPerPage=50'));
        $listed = array_column($this->json(), null, 'id')[$this->noteIds['private_1']];
        self::assertCount(3, $listed['tags']);

        // Replacing, not adding; an empty list and null clear.
        self::assertSame(200, $this->api('owner', 'PUT', $this->noteUri('private_1').'/tags', ['tag_ids' => [$tags['Beta']]]));
        self::assertSame(['Beta'], array_column($this->json()['tags'], 'name'));
        self::assertSame(200, $this->api('owner', 'PUT', $this->noteUri('private_1').'/tags', ['tag_ids' => []]));
        self::assertSame(200, $this->api('owner', 'PUT', $this->noteUri('private_1').'/category', ['category_id' => null]));
        self::assertSame([null, []], [$this->json()['category'], $this->json()['tags']]);

        $events = $this->auditEvents('private_1');
        $types = array_map(static fn (AuditEvent $e): string => $e->getEventType(), $events);
        self::assertSame(3, count(array_keys($types, AuditEvent::NOTE_TAGS_CHANGED, true)));
        self::assertSame(2, count(array_keys($types, AuditEvent::NOTE_CATEGORY_CHANGED, true)));
    }

    public function testEditingANoteKeepsItsTagsAndCategory(): void
    {
        $tags = $this->createItems('owner', '/api/tags', ['One', 'Two']);
        $category = $this->createItems('owner', '/api/categories', ['Projects'])['Projects'];
        $this->api('owner', 'PUT', $this->noteUri('shared_1').'/tags', ['tag_ids' => array_values($tags)]);
        $this->api('owner', 'PUT', $this->noteUri('shared_1').'/category', ['category_id' => $category]);

        foreach (['owner', 'editor'] as $persona) {
            self::assertSame(200, $this->api($persona, 'PUT', $this->noteUri('shared_1'), ['title' => 'edited by '.$persona, 'content' => 'c']));
            self::assertSame(['One', 'Two'], array_column($this->json()['tags'], 'name'), $persona);
            self::assertSame('Projects', $this->json()['category']['name'], $persona);
        }

        // The note payload cannot smuggle taxonomy in.
        self::assertSame(200, $this->api('editor', 'PUT', $this->noteUri('shared_1'), ['title' => 't', 'content' => 'c', 'tags' => [], 'category' => null]));
        self::assertSame(200, $this->api('viewer', 'GET', $this->noteUri('shared_1')));
        self::assertCount(2, $this->json()['tags']);
        self::assertSame('Projects', $this->json()['category']['name']);
    }

    public function testANoteHoldsAtMostTenTags(): void
    {
        $names = array_map(static fn (int $i): string => sprintf('tag %02d', $i), range(1, 11));
        $tags = array_values($this->createItems('owner', '/api/tags', $names));
        $uri = $this->noteUri('private_1').'/tags';

        self::assertSame(200, $this->api('owner', 'PUT', $uri, ['tag_ids' => array_slice($tags, 0, 10)]));
        self::assertCount(10, $this->json()['tags']);

        self::assertSame(422, $this->api('owner', 'PUT', $uri, ['tag_ids' => $tags]));
        self::assertSame(['propertyPath' => 'tag_ids', 'message' => 'A note can have at most 10 tags.'], $this->json()['violations'][0]);
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('private_1')));
        self::assertCount(10, $this->json()['tags']);

        // Swapping one of the ten for the eleventh stays within the limit.
        self::assertSame(200, $this->api('owner', 'PUT', $uri, ['tag_ids' => array_slice($tags, 1, 10)]));
        self::assertSame('tag 11', $this->json()['tags'][9]['name']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|string, int}>
     */
    public static function invalidAssociationPayloads(): iterable
    {
        $id = '00000000-0000-4000-8000-000000000000';
        yield 'tags: malformed JSON' => ['tags', '{"tag_ids": [', 400];
        yield 'tags: missing' => ['tags', [], 422];
        yield 'tags: null' => ['tags', ['tag_ids' => null], 422];
        yield 'tags: string' => ['tags', ['tag_ids' => $id], 422];
        yield 'tags: object' => ['tags', ['tag_ids' => ['a' => $id]], 422];
        yield 'tags: not an ID' => ['tags', ['tag_ids' => ['work']], 422];
        yield 'tags: number' => ['tags', ['tag_ids' => [1]], 422];
        yield 'tags: duplicate' => ['tags', ['tag_ids' => [$id, strtoupper($id)]], 422];
        yield 'tags: unexpected field' => ['tags', ['tag_ids' => [], 'category_id' => null], 422];
        yield 'tags: unknown ID' => ['tags', ['tag_ids' => [$id]], 404];
        yield 'category: missing' => ['category', [], 422];
        yield 'category: not an ID' => ['category', ['category_id' => 'projects'], 422];
        yield 'category: list' => ['category', ['category_id' => [$id]], 422];
        yield 'category: unexpected field' => ['category', ['category_id' => null, 'tag_ids' => []], 422];
        yield 'category: unknown ID' => ['category', ['category_id' => $id], 404];
    }

    #[DataProvider('invalidAssociationPayloads')]
    public function testAssociationValidation(string $what, array|string $body, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'PUT', $this->noteUri('private_1').'/'.$what, $body));
        self::assertIsString($this->json()['error']);
    }

    public function testEditorChangesAssociationsWithTheOwnersTaxonomyOnly(): void
    {
        $ownerTag = $this->createItems('owner', '/api/tags', ['Owner tag'])['Owner tag'];
        $ownerCategory = $this->createItems('owner', '/api/categories', ['Owner category'])['Owner category'];
        $editorTag = $this->createItems('editor', '/api/tags', ['Editor tag'])['Editor tag'];
        $editorCategory = $this->createItems('editor', '/api/categories', ['Editor category'])['Editor category'];
        $note = $this->noteUri('shared_1');

        // The editor sees what can be picked: the owner's taxonomy, not their own.
        self::assertSame(200, $this->api('editor', 'GET', $note.'/taxonomy'));
        self::assertSame([['Owner tag'], ['Owner category']], [array_column($this->json()['tags'], 'name'), array_column($this->json()['categories'], 'name')]);

        self::assertSame(200, $this->api('editor', 'PUT', $note.'/tags', ['tag_ids' => [$ownerTag]]));
        self::assertSame(200, $this->api('editor', 'PUT', $note.'/category', ['category_id' => $ownerCategory]));

        // Foreign IDs are rejected for everyone, and all-or-nothing.
        self::assertSame(404, $this->api('editor', 'PUT', $note.'/tags', ['tag_ids' => [$ownerTag, $editorTag]]));
        self::assertSame(404, $this->api('editor', 'PUT', $note.'/category', ['category_id' => $editorCategory]));
        self::assertSame(404, $this->api('owner', 'PUT', $note.'/tags', ['tag_ids' => [$editorTag]]));
        self::assertSame(404, $this->api('owner', 'PUT', $note.'/category', ['category_id' => $editorCategory]));

        // The editor cannot touch the taxonomy itself.
        self::assertSame(404, $this->api('editor', 'PATCH', '/api/tags/'.$ownerTag, ['name' => 'Renamed']));
        self::assertSame(404, $this->api('editor', 'DELETE', '/api/categories/'.$ownerCategory));

        self::assertSame(200, $this->api('owner', 'GET', $note));
        self::assertSame([['Owner tag'], 'Owner category'], [array_column($this->json()['tags'], 'name'), $this->json()['category']['name']]);
    }

    public function testViewerAndStrangersCannotChangeAssociations(): void
    {
        $tag = $this->createItems('owner', '/api/tags', ['Work'])['Work'];
        $category = $this->createItems('owner', '/api/categories', ['Projects'])['Projects'];
        $note = $this->noteUri('shared_1');
        $this->api('owner', 'PUT', $note.'/tags', ['tag_ids' => [$tag]]);

        $requests = [['PUT', '/tags', ['tag_ids' => []]], ['PUT', '/category', ['category_id' => $category]], ['GET', '/taxonomy', null]];
        foreach ($requests as [$method, $path, $body]) {
            self::assertSame(403, $this->api('viewer', $method, $note.$path, $body), 'viewer '.$path);
            self::assertSame(404, $this->api('unrelated', $method, $note.$path, $body), 'unrelated '.$path);
            self::assertSame(401, $this->api(null, $method, $note.$path, $body), 'anonymous '.$path);
            // A private note of someone else is invisible even to that owner's editor.
            self::assertSame(404, $this->api('editor', $method, $this->noteUri('private_1').$path, $body), 'editor on private note '.$path);
        }

        // The viewer still sees the tags of the note.
        self::assertSame(200, $this->api('viewer', 'GET', $note));
        self::assertSame([['Work'], null], [array_column($this->json()['tags'], 'name'), $this->json()['category']]);
    }

    public function testDeletingTagOrCategoryDetachesNotes(): void
    {
        $tags = $this->createItems('owner', '/api/tags', ['Keep', 'Drop']);
        $category = $this->createItems('owner', '/api/categories', ['Projects'])['Projects'];
        foreach (['private_1', 'shared_1'] as $key) {
            $this->api('owner', 'PUT', $this->noteUri($key).'/tags', ['tag_ids' => array_values($tags)]);
            $this->api('owner', 'PUT', $this->noteUri($key).'/category', ['category_id' => $category]);
        }

        self::assertSame(204, $this->api('owner', 'DELETE', '/api/tags/'.$tags['Drop']));
        self::assertSame(204, $this->api('owner', 'DELETE', '/api/categories/'.$category));

        foreach (['private_1', 'shared_1'] as $key) {
            self::assertSame(200, $this->api('owner', 'GET', $this->noteUri($key)));
            self::assertSame([['Keep'], null], [array_column($this->json()['tags'], 'name'), $this->json()['category']]);
            self::assertSame($this->runId.' '.$key, $this->json()['title']);
        }
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?itemsPerPage=50'));
        self::assertCount(4, $this->listedNoteKeys());

        $deleted = $this->em()->getRepository(AuditEvent::class)->findOneBy(['subjectId' => $tags['Drop'], 'eventType' => AuditEvent::TAG_DELETED]);
        self::assertSame(['detached_notes' => 2], $deleted->getMetadata());
    }

    public function testDeletingANoteKeepsTheTaxonomy(): void
    {
        $tag = $this->createItems('owner', '/api/tags', ['Work'])['Work'];
        $this->api('owner', 'PUT', $this->noteUri('private_1').'/tags', ['tag_ids' => [$tag]]);

        self::assertSame(204, $this->api('owner', 'DELETE', $this->noteUri('private_1')));
        self::assertSame(200, $this->api('owner', 'GET', '/api/tags'));
        self::assertSame(['Work'], array_column($this->json()['items'], 'name'));
    }

    public function testFilterNotesByTagAndCategory(): void
    {
        $t = $this->createItems('owner', '/api/tags', ['A', 'B', 'C']);
        $c = $this->createItems('owner', '/api/categories', ['Projects'])['Projects'];
        $this->api('owner', 'PUT', $this->noteUri('private_1').'/tags', ['tag_ids' => [$t['A'], $t['B']]]);
        $this->api('owner', 'PUT', $this->noteUri('private_2').'/tags', ['tag_ids' => [$t['B']]]);
        $this->api('owner', 'PUT', $this->noteUri('shared_1').'/tags', ['tag_ids' => [$t['A'], $t['B']]]);
        $this->api('owner', 'PUT', $this->noteUri('shared_1').'/category', ['category_id' => $c]);

        $cases = [
            ['owner', 'tagIds='.$t['A'], ['private_1', 'shared_1']],
            ['owner', 'tagIds='.$t['A'].','.$t['B'], ['private_1', 'private_2', 'shared_1']],
            ['owner', 'tagIds='.$t['A'].','.$t['B'].'&tagMatch=any', ['private_1', 'private_2', 'shared_1']],
            ['owner', 'tagIds='.$t['A'].','.$t['B'].'&tagMatch=all', ['private_1', 'shared_1']],
            ['owner', 'tagIds='.$t['A'].','.$t['C'].'&tagMatch=all', []],
            ['owner', 'tagIds='.$t['C'], []],
            ['owner', 'tagIds='.strtoupper($t['A']), ['private_1', 'shared_1']],
            ['owner', 'categoryId='.$c, ['shared_1']],
            ['owner', 'categoryId='.$c.'&tagIds='.$t['B'].'&q=shared', ['shared_1']],
            ['owner', 'tagIds=00000000-0000-4000-8000-000000000000', []],
            // Filters never widen access: others only get what is shared with them.
            ['viewer', 'scope=all&tagIds='.$t['B'], ['shared_1']],
            ['editor', 'scope=shared&categoryId='.$c, ['shared_1']],
            ['unrelated', 'scope=all&tagIds='.$t['A'].','.$t['B'], []],
            ['viewer', 'tagIds='.$t['B'], []],
        ];
        foreach ($cases as [$persona, $query, $expected]) {
            self::assertSame(200, $this->api($persona, 'GET', '/api/notes?itemsPerPage=50&'.$query), $query);
            self::assertSame($expected, $this->listedNoteKeys(), $persona.' '.$query);
        }

        // Pagination counts notes, not note-tag pairs.
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?itemsPerPage=2&sort[title]=asc&tagIds='.$t['A'].','.$t['B']));
        self::assertSame(['private_1', 'private_2'], $this->listedNoteKeys());

        $ids = implode(',', array_fill(0, 11, $t['A']));
        foreach (['tagIds=work', 'tagIds[]='.$t['A'], 'tagIds='.$ids, 'tagIds='.$t['A'].'&tagMatch=some', 'categoryId=projects', 'categoryId[]='.$c] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', '/api/notes?'.$query), $query);
        }
    }

    public function testOpenApiDocumentsTaxonomyRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $doc = $this->json();

        foreach (['/api/tags', '/api/categories'] as $path) {
            self::assertEqualsCanonicalizing(['201', '400', '409', '422', '401', '500'], array_map('strval', array_keys($doc['paths'][$path]['post']['responses'])));
            self::assertEqualsCanonicalizing(['200', '400', '404', '409', '422', '401', '500'], array_map('strval', array_keys($doc['paths'][$path.'/{id}']['patch']['responses'])));
            self::assertArrayHasKey('204', $doc['paths'][$path.'/{id}']['delete']['responses']);
            self::assertArrayHasKey('200', $doc['paths'][$path]['get']['responses']);
        }
        self::assertEqualsCanonicalizing(['200', '400', '404', '422', '401', '403', '500'], array_map('strval', array_keys($doc['paths']['/api/notes/{id}/tags']['put']['responses'])));
        self::assertArrayHasKey('put', $doc['paths']['/api/notes/{id}/category']);
        self::assertArrayHasKey('get', $doc['paths']['/api/notes/{id}/taxonomy']);

        $parameters = array_column($doc['paths']['/api/notes']['get']['parameters'], 'name');
        self::assertContains('tagIds', $parameters);
        self::assertContains('tagMatch', $parameters);
        self::assertContains('categoryId', $parameters);
        $note = $doc['components']['schemas']['Note-note.read']['properties'];
        self::assertArrayHasKey('tags', $note);
        self::assertArrayHasKey('category', $note);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string> name => ID
     */
    private function createItems(string $persona, string $uri, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            self::assertSame(201, $this->api($persona, 'POST', $uri, ['name' => $name]));
            $ids[$name] = $this->json()['id'];
        }

        return $ids;
    }
}
