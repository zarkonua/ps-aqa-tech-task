<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;

final class NoteBulkTest extends ApiTestCase
{
    private const MISSING = '00000000-0000-4000-8000-000000000000';

    /** @var array<string, string> name => tag ID (owner's) */
    private array $tags = [];
    private string $category;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['A', 'B', 'C'] as $name) {
            self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => $name]));
            $this->tags[$name] = $this->json()['id'];
        }
        self::assertSame(201, $this->api('owner', 'POST', '/api/categories', ['name' => 'Projects']));
        $this->category = $this->json()['id'];
    }

    public function testTwentyNotesAreUpdatedAndARepeatIsSafe(): void
    {
        $ids = $this->createNotes('owner', 20);
        $body = ['operation' => 'addTags', 'ids' => $ids, 'payload' => ['tag_ids' => [$this->tags['A'], $this->tags['B']]]];

        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', $body, ['X-Correlation-ID' => 'bulk-test-0001']));
        $result = $this->json();
        self::assertSame(['operation', 'total', 'succeeded', 'failed', 'correlation_id'], array_keys($result));
        self::assertSame(['addTags', 20, $ids, [], 'bulk-test-0001'], array_values($result));
        foreach ($ids as $id) {
            self::assertSame(['A', 'B'], $this->tagNames($id));
        }
        self::assertSame(20, $this->countEvents(AuditEvent::NOTE_TAGS_CHANGED, $ids));

        // The same request again: same answer, nothing changes, nothing more is audited.
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', $body));
        self::assertSame([20, $ids, []], [$this->json()['total'], $this->json()['succeeded'], $this->json()['failed']]);
        self::assertSame(['A', 'B'], $this->tagNames($ids[0]));
        self::assertSame(20, $this->countEvents(AuditEvent::NOTE_TAGS_CHANGED, $ids));

        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'setCategory', 'ids' => $ids, 'payload' => ['category_id' => $this->category]]));
        self::assertCount(20, $this->json()['succeeded']);
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'removeTags', 'ids' => $ids, 'payload' => ['tag_ids' => [$this->tags['A'], $this->tags['C']]]]));
        self::assertCount(20, $this->json()['succeeded']);
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes/'.$ids[7]));
        self::assertSame([['B'], 'Projects'], [array_column($this->json()['tags'], 'name'), $this->json()['category']['name']]);

        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'setCategory', 'ids' => $ids, 'payload' => ['category_id' => null]]));
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes/'.$ids[7]));
        self::assertNull($this->json()['category']);
    }

    public function testMixedPermissionsGivePartialSuccess(): void
    {
        $foreign = $this->createNotes('unrelated', 1)[0];
        $ids = [$this->noteIds['private_1'], $this->noteIds['shared_1'], $this->noteIds['shared_2'], $foreign, self::MISSING, 'not-an-id'];

        // The editor can edit shared_1 only: shared_2 is view-only, the rest is invisible to them.
        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'addTags', 'ids' => $ids, 'payload' => ['tag_ids' => [$this->tags['A']]]]));
        $result = $this->json();
        self::assertSame(6, $result['total']);
        self::assertSame([$this->noteIds['shared_1']], $result['succeeded']);
        self::assertSame(
            [
                $this->noteIds['private_1'] => 'not_found',
                $this->noteIds['shared_2'] => 'not_found',
                $foreign => 'not_found',
                self::MISSING => 'not_found',
                'not-an-id' => 'not_found',
            ],
            array_column($result['failed'], 'code', 'id'),
        );
        self::assertSame(['Note not found.'], array_values(array_unique(array_column($result['failed'], 'message'))));

        // The viewer sees shared_1 and shared_2 but may change neither.
        self::assertSame(200, $this->api('viewer', 'POST', '/api/notes/bulk', ['operation' => 'setCategory', 'ids' => $ids, 'payload' => ['category_id' => null]]));
        self::assertSame([], $this->json()['succeeded']);
        $codes = array_column($this->json()['failed'], 'code', 'id');
        self::assertSame(['forbidden', 'forbidden', 'not_found'], [$codes[$this->noteIds['shared_1']], $codes[$this->noteIds['shared_2']], $codes[$this->noteIds['private_1']]]);

        // Only what was allowed happened.
        self::assertSame(['A'], $this->tagNames($this->noteIds['shared_1']));
        foreach ([$this->noteIds['private_1'], $this->noteIds['shared_2']] as $id) {
            self::assertSame([], $this->tagNames($id));
        }
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes/'.$foreign));
        self::assertSame([[], null, 'note 0'], [$this->json()['tags'], $this->json()['category'], $this->json()['title']]);
    }

    public function testDeleteIsForOwnersOnly(): void
    {
        $own = $this->createNotes('editor', 2);
        $ids = [...$own, $this->noteIds['shared_1'], $this->noteIds['private_1']];

        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'delete', 'ids' => $ids]));
        $result = $this->json();
        self::assertSame($own, $result['succeeded']);
        self::assertSame(
            [$this->noteIds['shared_1'] => 'forbidden', $this->noteIds['private_1'] => 'not_found'],
            array_column($result['failed'], 'code', 'id'),
        );
        self::assertSame('Only the note owner can delete this note.', $result['failed'][0]['message']);

        self::assertSame(404, $this->api('editor', 'GET', '/api/notes/'.$own[0]));
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('shared_1')));
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('private_1')));
        self::assertSame(2, $this->countEvents(AuditEvent::NOTE_DELETED, $own));

        // Retrying finds nothing left to delete and harms nothing.
        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'delete', 'ids' => $own, 'payload' => []]));
        self::assertSame([[], ['not_found', 'not_found']], [$this->json()['succeeded'], array_column($this->json()['failed'], 'code')]);

        // The owner deletes a shared note: its shares go with it and are audited.
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'delete', 'ids' => [$this->noteIds['shared_1']]]));
        self::assertSame([$this->noteIds['shared_1']], $this->json()['succeeded']);
        self::assertSame(404, $this->api('viewer', 'GET', $this->noteUri('shared_1')));
        self::assertCount(2, $this->auditEvents('shared_1', AuditEvent::SHARE_REMOVED_WITH_NOTE));
    }

    public function testDuplicateIdsAreProcessedOnce(): void
    {
        $id = $this->noteIds['private_1'];
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', [
            'operation' => 'addTags',
            'ids' => [$id, $id, strtoupper($id), $this->noteIds['private_2'], $id],
            'payload' => ['tag_ids' => [$this->tags['A'], $this->tags['A']]],
        ]));
        self::assertSame([2, [$id, $this->noteIds['private_2']], []], [$this->json()['total'], $this->json()['succeeded'], $this->json()['failed']]);
        self::assertSame(1, $this->countEvents(AuditEvent::NOTE_TAGS_CHANGED, [$id]));
    }

    public function testIdLimit(): void
    {
        $ids = $this->createNotes('owner', 101);

        self::assertSame(422, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'setCategory', 'ids' => $ids, 'payload' => ['category_id' => $this->category]]));
        self::assertSame(['propertyPath' => 'ids', 'message' => 'At most 100 note IDs can be processed at once.'], $this->json()['violations'][0]);
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?categoryId='.$this->category));
        self::assertSame([], $this->json(), 'nothing was processed');

        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'setCategory', 'ids' => array_slice($ids, 0, 100), 'payload' => ['category_id' => $this->category]]));
        self::assertSame([100, 100, 0], [$this->json()['total'], count($this->json()['succeeded']), count($this->json()['failed'])]);
        self::assertSame(200, $this->api('owner', 'GET', '/api/notes?limit=1&categoryId='.$this->category));
        self::assertSame('100', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testTaxonomyComesFromEachNotesOwner(): void
    {
        self::assertSame(201, $this->api('editor', 'POST', '/api/tags', ['name' => 'Editor tag']));
        $editorTag = $this->json()['id'];
        $own = $this->createNotes('editor', 1)[0];
        $ids = [$own, $this->noteIds['shared_1']];

        // The editor's tag fits the editor's own note, not the owner's note.
        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'addTags', 'ids' => $ids, 'payload' => ['tag_ids' => [$editorTag]]]));
        self::assertSame([$own], $this->json()['succeeded']);
        self::assertSame([['id' => $this->noteIds['shared_1'], 'code' => 'tag_not_found', 'message' => "A tag is not one of the note owner's tags."]], $this->json()['failed']);

        // And the other way round with the owner's tag and category.
        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'addTags', 'ids' => $ids, 'payload' => ['tag_ids' => [$this->tags['A']]]]));
        self::assertSame([[$this->noteIds['shared_1']], ['tag_not_found']], [$this->json()['succeeded'], array_column($this->json()['failed'], 'code')]);
        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'setCategory', 'ids' => $ids, 'payload' => ['category_id' => $this->category]]));
        self::assertSame([[$this->noteIds['shared_1']], ['category_not_found']], [$this->json()['succeeded'], array_column($this->json()['failed'], 'code')]);
        self::assertSame(200, $this->api('editor', 'POST', '/api/notes/bulk', ['operation' => 'removeTags', 'ids' => $ids, 'payload' => ['tag_ids' => [self::MISSING]]]));
        self::assertSame(['tag_not_found', 'tag_not_found'], array_column($this->json()['failed'], 'code'));

        self::assertSame(['A'], $this->tagNames($this->noteIds['shared_1']));
    }

    public function testAddTagsRespectsTheTagLimit(): void
    {
        $tagIds = array_values($this->tags);
        for ($i = 4; $i <= 11; ++$i) {
            self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => 'T'.$i]));
            $tagIds[] = $this->json()['id'];
        }
        $full = $this->noteIds['private_1'];
        $roomy = $this->noteIds['private_2'];
        self::assertSame(200, $this->api('owner', 'PUT', '/api/notes/'.$full.'/tags', ['tag_ids' => array_slice($tagIds, 0, 10)]));

        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'addTags', 'ids' => [$full, $roomy], 'payload' => ['tag_ids' => [$tagIds[9], $tagIds[10]]]]));
        self::assertSame([$roomy], $this->json()['succeeded']);
        self::assertSame([['id' => $full, 'code' => 'tag_limit', 'message' => 'A note can have at most 10 tags.']], $this->json()['failed']);
        self::assertCount(10, $this->tagNames($full));
        self::assertNotContains('T11', $this->tagNames($full));

        // Adding tags the note already has needs no room.
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'addTags', 'ids' => [$full], 'payload' => ['tag_ids' => array_slice($tagIds, 0, 10)]]));
        self::assertSame([$full], $this->json()['succeeded']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int, string|null}>
     */
    public static function invalidRequests(): iterable
    {
        $id = self::MISSING;
        yield 'malformed JSON' => ['{"operation": "delete", "ids": [', 400, null];
        yield 'JSON list' => ['["delete"]', 400, null];
        yield 'empty body' => ['', 422, 'operation'];
        yield 'missing operation' => [['ids' => [$id]], 422, 'operation'];
        yield 'unsupported operation' => [['operation' => 'archive', 'ids' => [$id]], 422, 'operation'];
        yield 'operation wrong case' => [['operation' => 'DELETE', 'ids' => [$id]], 422, 'operation'];
        yield 'operation as number' => [['operation' => 1, 'ids' => [$id]], 422, 'operation'];
        yield 'missing ids' => [['operation' => 'delete'], 422, 'ids'];
        yield 'null ids' => [['operation' => 'delete', 'ids' => null], 422, 'ids'];
        yield 'empty ids' => [['operation' => 'delete', 'ids' => []], 422, 'ids'];
        yield 'ids as string' => [['operation' => 'delete', 'ids' => $id], 422, 'ids'];
        yield 'ids as object' => [['operation' => 'delete', 'ids' => ['a' => $id]], 422, 'ids'];
        yield 'id as number' => [['operation' => 'delete', 'ids' => [$id, 5]], 422, 'ids[1]'];
        yield 'id as null' => [['operation' => 'delete', 'ids' => [null]], 422, 'ids[0]'];
        yield 'unexpected field' => [['operation' => 'delete', 'ids' => [$id], 'force' => true], 422, 'force'];
        yield 'delete with payload' => [['operation' => 'delete', 'ids' => [$id], 'payload' => ['category_id' => null]], 422, 'payload.category_id'];
        yield 'payload as list' => [['operation' => 'addTags', 'ids' => [$id], 'payload' => [$id]], 422, 'payload'];
        yield 'payload as string' => [['operation' => 'addTags', 'ids' => [$id], 'payload' => 'x'], 422, 'payload'];
        yield 'addTags without tags' => [['operation' => 'addTags', 'ids' => [$id]], 422, 'payload.tag_ids'];
        yield 'addTags empty tags' => [['operation' => 'addTags', 'ids' => [$id], 'payload' => ['tag_ids' => []]], 422, 'payload.tag_ids'];
        yield 'addTags 11 tags' => [['operation' => 'addTags', 'ids' => [$id], 'payload' => ['tag_ids' => array_map(static fn (int $i): string => sprintf('00000000-0000-4000-8000-0000000000%02d', $i), range(1, 11))]], 422, 'payload.tag_ids'];
        yield 'removeTags bad tag ID' => [['operation' => 'removeTags', 'ids' => [$id], 'payload' => ['tag_ids' => ['<script>']]], 422, 'payload.tag_ids'];
        yield 'setCategory without category' => [['operation' => 'setCategory', 'ids' => [$id], 'payload' => []], 422, 'payload.category_id'];
        yield 'setCategory bad category ID' => [['operation' => 'setCategory', 'ids' => [$id], 'payload' => ['category_id' => 'projects']], 422, 'payload.category_id'];
        yield 'setCategory extra field' => [['operation' => 'setCategory', 'ids' => [$id], 'payload' => ['category_id' => null, 'tag_ids' => []]], 422, 'payload.tag_ids'];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsAreRejectedAsAWhole(array|string $body, int $expectedStatus, ?string $expectedField): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'POST', '/api/notes/bulk', $body));
        if (null !== $expectedField) {
            self::assertContains($expectedField, array_column($this->json()['violations'], 'propertyPath'));
        }
    }

    public function testAuthenticationAndThrottling(): void
    {
        $body = ['operation' => 'delete', 'ids' => [$this->noteIds['private_1']]];
        self::assertSame(401, $this->api(null, 'POST', '/api/notes/bulk', $body));
        self::assertSame(401, $this->api(null, 'POST', '/api/notes/bulk', $body, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]));
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('private_1')));

        $harmless = ['operation' => 'setCategory', 'ids' => [self::MISSING], 'payload' => ['category_id' => null]];
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(200, $this->api('viewer', 'POST', '/api/notes/bulk', $harmless));
        }
        self::assertSame(429, $this->api('viewer', 'POST', '/api/notes/bulk', $harmless));
        self::assertGreaterThan(0, $this->json()['retry_after']);
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', $harmless));
    }

    public function testOpenApiDocumentsTheBulkRoute(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $post = $this->json()['paths']['/api/notes/bulk']['post'];

        self::assertEqualsCanonicalizing(['200', '400', '401', '422', '429', '500'], array_map('strval', array_keys($post['responses'])));
        self::assertSame(['setCategory', 'addTags', 'removeTags', 'delete'], $post['requestBody']['content']['application/json']['schema']['properties']['operation']['enum']);
    }

    /**
     * @return list<string> IDs of the created notes
     */
    private function createNotes(string $persona, int $count): array
    {
        $em = $this->em();
        $owner = $em->find(User::class, $this->userIds[$persona]);
        $notes = [];
        for ($i = 0; $i < $count; ++$i) {
            $notes[] = $note = (new Note())->setOwner($owner)->setTitle('note '.$i)->setContent('c');
            $em->persist($note);
        }
        $em->flush();

        return array_map(static fn (Note $note): string => (string) $note->getId(), $notes);
    }

    /**
     * @return list<string>
     */
    private function tagNames(string $noteId): array
    {
        $names = array_map(static fn ($tag): string => $tag->getName(), $this->em()->find(Note::class, $noteId)->getTags()->toArray());
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $noteIds
     */
    private function countEvents(string $type, array $noteIds): int
    {
        return $this->em()->getRepository(AuditEvent::class)->count(['eventType' => $type, 'noteId' => $noteIds]);
    }
}
