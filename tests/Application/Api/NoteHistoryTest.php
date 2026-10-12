<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\NoteRevision;

final class NoteHistoryTest extends ApiTestCase
{
    private string $noteId;
    private string $uri;

    protected function setUp(): void
    {
        parent::setUp();

        // Created through the API, so it has a history from its first revision. Shared like "shared_1".
        self::assertSame(201, $this->api('owner', 'POST', '/api/notes', ['title' => 'First title', 'content' => 'first content']));
        $this->noteId = $this->json()['id'];
        $this->uri = '/api/notes/'.$this->noteId;
        foreach (['viewer' => 'viewer', 'editor' => 'editor'] as $persona => $role) {
            self::assertSame(201, $this->api('owner', 'POST', $this->uri.'/shares', ['email' => $this->email($persona), 'role' => $role]));
        }
    }

    public function testTwoSavesAppearInOrder(): void
    {
        self::assertSame(200, $this->api('owner', 'GET', $this->uri));
        self::assertSame(1, $this->json()['version']);
        self::assertSame('"1"', $this->etag());
        $createdAt = $this->json()['created_at'];

        self::assertSame(200, $this->api('owner', 'PUT', $this->uri, ['title' => 'Second title', 'content' => 'second content']));
        self::assertSame([2, '"2"'], [$this->json()['version'], $this->etag()]);
        self::assertSame($createdAt, $this->json()['created_at'], 'saving does not move the creation time');

        self::assertSame(200, $this->api('editor', 'PATCH', $this->uri, ['content' => "third content\nüñí 日本語 💡"], ['If-Match' => '"2"']));
        self::assertSame([3, '"3"', 'Second title'], [$this->json()['version'], $this->etag(), $this->json()['title']]);

        self::assertSame(200, $this->api('viewer', 'GET', $this->uri.'/revisions'));
        $list = $this->json();
        self::assertSame(['items', 'total', 'page', 'items_per_page', 'current_version'], array_keys($list));
        self::assertSame([3, 1, 20, 3], [$list['total'], $list['page'], $list['items_per_page'], $list['current_version']]);
        self::assertSame([3, 2, 1], array_column($list['items'], 'revision'));
        self::assertSame(['update', 'update', 'create'], array_column($list['items'], 'kind'));
        self::assertSame(['Second title', 'Second title', 'First title'], array_column($list['items'], 'title'));
        self::assertSame(['revision', 'kind', 'restored_from', 'title', 'actor', 'created_at'], array_keys($list['items'][0]));
        self::assertSame(['id' => $this->userIds['editor'], 'email' => $this->email('editor')], $list['items'][0]['actor']);
        self::assertSame($this->userIds['owner'], $list['items'][2]['actor']['id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $list['items'][0]['created_at']);

        self::assertSame(200, $this->api('viewer', 'GET', $this->uri.'/revisions/1'));
        self::assertSame(['First title', 'first content'], [$this->json()['title'], $this->json()['content']]);
        self::assertSame(200, $this->api('viewer', 'GET', $this->uri.'/revisions/3'));
        self::assertSame("third content\nüñí 日本語 💡", $this->json()['content']);

        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/revisions?page=2&itemsPerPage=2'));
        self::assertSame([1], array_column($this->json()['items'], 'revision'));
    }

    public function testSavingTheSameTextIsNotARevision(): void
    {
        self::assertSame(200, $this->api('owner', 'PUT', $this->uri, ['title' => 'Changed', 'content' => 'changed']));
        $this->em()->getConnection()->executeStatement("UPDATE notes SET updated_at = '2026-01-01 00:00:00' WHERE id = ?", [$this->noteId]);

        self::assertSame(200, $this->api('owner', 'PUT', $this->uri, ['title' => '  Changed ', 'content' => 'changed']));
        self::assertSame([2, '2026-01-01T00:00:00+00:00'], [$this->json()['version'], $this->json()['updated_at']]);
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'Changed'], ['If-Match' => '"2"']));
        self::assertSame([2, '"2"', '2026-01-01T00:00:00+00:00'], [$this->json()['version'], $this->etag(), $this->json()['updated_at']]);
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, [], ['If-Match' => '"2"']));

        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/revisions'));
        self::assertSame([2, 1], array_column($this->json()['items'], 'revision'));
    }

    public function testOnlyTextChangesMakeRevisions(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => 'T']));
        self::assertSame(200, $this->api('owner', 'PUT', $this->uri.'/tags', ['tag_ids' => [$this->json()['id']]]));
        self::assertSame(201, $this->api('owner', 'POST', '/api/categories', ['name' => 'C']));
        self::assertSame(200, $this->api('owner', 'PUT', $this->uri.'/category', ['category_id' => $this->json()['id']]));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri.'/shares/'.$this->shareIdOf('viewer'), ['role' => 'editor']));

        self::assertSame(200, $this->api('owner', 'GET', $this->uri));
        self::assertSame([1, 'T', 'C'], [$this->json()['version'], $this->json()['tags'][0]['name'], $this->json()['category']['name']]);
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/revisions'));
        self::assertSame(1, $this->json()['total']);

        // And a text change keeps tags and category.
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'New'], ['If-Match' => '"1"']));
        self::assertSame([2, 'T', 'C', 'first content'], [$this->json()['version'], $this->json()['tags'][0]['name'], $this->json()['category']['name'], $this->json()['content']]);
    }

    public function testStaleSaveNeverOverwrites(): void
    {
        // Two people open version 1. The owner saves first.
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['content' => 'saved by owner'], ['If-Match' => '"1"']));

        foreach ([['PATCH', ['content' => 'saved by editor']], ['PUT', ['title' => 'x', 'content' => 'saved by editor']]] as [$method, $body]) {
            self::assertSame(412, $this->api('editor', $method, $this->uri, $body, ['If-Match' => '"1"']));
            self::assertSame(412, $this->json()['status']);
            self::assertStringContainsString('changed since', $this->json()['detail']);
        }
        self::assertSame(200, $this->api('owner', 'GET', $this->uri));
        self::assertSame(['saved by owner', 2, 'First title'], [$this->json()['content'], $this->json()['version'], $this->json()['title']]);

        // After reloading, the editor's change goes in on top.
        self::assertSame(200, $this->api('editor', 'PATCH', $this->uri, ['content' => 'saved by editor'], ['If-Match' => '"2"']));
        self::assertSame(3, $this->json()['version']);
    }

    public function testIfMatchForms(): void
    {
        self::assertSame(428, $this->api('owner', 'PATCH', $this->uri, ['title' => 'a']));
        self::assertSame(428, $this->json()['status']);
        self::assertSame(428, $this->api('owner', 'PATCH', $this->uri, ['title' => 'a'], ['If-Match' => ' ']));

        foreach (['"abc"', '"1", "2"', '-1', '"1.5"', '<script>'] as $malformed) {
            self::assertSame(412, $this->api('owner', 'PATCH', $this->uri, ['title' => 'a'], ['If-Match' => $malformed]), $malformed);
        }
        foreach (['"0"', '"2"', '"999999"'] as $wrong) {
            self::assertSame(412, $this->api('owner', 'PATCH', $this->uri, ['title' => 'a'], ['If-Match' => $wrong]), $wrong);
        }
        self::assertSame(200, $this->api('owner', 'GET', $this->uri));
        self::assertSame(['First title', 1], [$this->json()['title'], $this->json()['version']]);

        // Accepted: quoted, bare, weak, and "*" for "whatever it is now".
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'b'], ['If-Match' => '"1"']));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'c'], ['If-Match' => '2']));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'd'], ['If-Match' => 'W/"3"']));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'e'], ['If-Match' => '*']));
        self::assertSame(5, $this->json()['version']);

        // PUT stays usable without the header (last write wins), as before.
        self::assertSame(200, $this->api('owner', 'PUT', $this->uri, ['title' => 'f', 'content' => 'c']));
        self::assertSame(6, $this->json()['version']);
    }

    public function testPatchValidationAndRoles(): void
    {
        $headers = ['If-Match' => '"1"'];
        self::assertSame(422, $this->api('owner', 'PATCH', $this->uri, ['title' => ''], $headers));
        self::assertSame(422, $this->api('owner', 'PATCH', $this->uri, ['content' => ''], $headers));
        self::assertSame(422, $this->api('owner', 'PATCH', $this->uri, ['title' => str_repeat('a', 256)], $headers));
        self::assertSame(400, $this->api('owner', 'PATCH', $this->uri, '{"title": ', $headers));
        self::assertSame(403, $this->api('viewer', 'PATCH', $this->uri, ['title' => 'x'], $headers));
        self::assertSame(404, $this->api('unrelated', 'PATCH', $this->uri, ['title' => 'x'], $headers));
        self::assertSame(401, $this->api(null, 'PATCH', $this->uri, ['title' => 'x'], $headers));

        // Fields that are not part of a note cannot be set through it.
        self::assertSame(200, $this->api('editor', 'PATCH', $this->uri, ['title' => 'ok', 'version' => 99, 'owner_id' => $this->userIds['editor'], 'role' => 'owner'], $headers));
        self::assertSame([2, $this->userIds['owner'], 'editor'], [$this->json()['version'], $this->json()['owner_id'], $this->json()['role']]);
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/revisions'));
        self::assertSame(2, $this->json()['total']);
    }

    public function testRestoreCreatesANewRevision(): void
    {
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'Second', 'content' => '<b>second</b> & <script>alert(1)</script>'], ['If-Match' => '"1"']));

        self::assertSame(200, $this->api('editor', 'POST', $this->uri.'/revisions/1/restore', null, ['If-Match' => '"2"', 'X-Correlation-ID' => 'restore-test-1']));
        self::assertSame(['note_id' => $this->noteId, 'version' => 3, 'restored_from' => 1, 'title' => 'First title', 'content' => 'first content'], $this->json());
        self::assertSame('"3"', $this->etag());

        self::assertSame(200, $this->api('viewer', 'GET', $this->uri));
        self::assertSame(['First title', 'first content', 3], [$this->json()['title'], $this->json()['content'], $this->json()['version']]);

        // History is extended, never rewritten.
        self::assertSame(200, $this->api('viewer', 'GET', $this->uri.'/revisions'));
        self::assertSame([[3, 'restore', 1], [2, 'update', null], [1, 'create', null]], array_map(static fn (array $r): array => [$r['revision'], $r['kind'], $r['restored_from']], $this->json()['items']));
        self::assertSame($this->userIds['editor'], $this->json()['items'][0]['actor']['id']);
        self::assertSame(200, $this->api('viewer', 'GET', $this->uri.'/revisions/2'));
        self::assertSame('<b>second</b> & <script>alert(1)</script>', $this->json()['content']);
        self::assertStringNotContainsString('<script>', (string) $this->client->getResponse()->getContent(), 'markup is escaped in the JSON response');

        $events = $this->em()->getRepository(AuditEvent::class)->findBy(['noteId' => $this->noteId, 'eventType' => AuditEvent::NOTE_RESTORED]);
        self::assertCount(1, $events);
        self::assertEquals(['from_revision' => 1, 'new_revision' => 3], $events[0]->getMetadata());
        self::assertSame([$this->userIds['editor'], 'restore-test-1'], [$events[0]->getActorId(), $events[0]->getCorrelationId()]);

        // Restoring the text the note already has changes nothing.
        self::assertSame(200, $this->api('owner', 'POST', $this->uri.'/revisions/1/restore', null, ['If-Match' => '"3"']));
        self::assertSame(3, $this->json()['version']);
        self::assertSame(200, $this->api('owner', 'POST', $this->uri.'/revisions/3/restore', null, ['If-Match' => '*']));
        self::assertSame(3, $this->em()->getRepository(NoteRevision::class)->count(['note' => $this->noteId]));
    }

    public function testRestoreRefusals(): void
    {
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['content' => 'second'], ['If-Match' => '"1"']));
        $restore = $this->uri.'/revisions/1/restore';

        self::assertSame(428, $this->api('owner', 'POST', $restore));
        self::assertSame(['error' => 'Send the current version of the note in an If-Match header.'], $this->json());
        // Stale: based on version 1, the note is at 2.
        self::assertSame(412, $this->api('owner', 'POST', $restore, null, ['If-Match' => '"1"']));
        self::assertSame(412, $this->api('owner', 'POST', $restore, null, ['If-Match' => 'nonsense']));
        self::assertSame(403, $this->api('viewer', 'POST', $restore, null, ['If-Match' => '"2"']));
        self::assertSame(['error' => 'Your role does not allow restoring revisions of this note.'], $this->json());
        self::assertSame(404, $this->api('unrelated', 'POST', $restore, null, ['If-Match' => '"2"']));
        self::assertSame(401, $this->api(null, 'POST', $restore, null, ['If-Match' => '"2"']));

        foreach (['3', '0', '-1', '999999999', 'abc', '1.0', '01', '1%20'] as $rev) {
            self::assertSame(404, $this->api('owner', 'POST', $this->uri.'/revisions/'.$rev.'/restore', null, ['If-Match' => '"2"']), $rev);
        }

        self::assertSame(200, $this->api('owner', 'GET', $this->uri));
        self::assertSame(['second', 2], [$this->json()['content'], $this->json()['version']]);
    }

    public function testHistoryFollowsTheNotesPermissions(): void
    {
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/notes', ['title' => 'Foreign', 'content' => 'secret']));
        $foreign = '/api/notes/'.$this->json()['id'];

        foreach (['owner', 'editor', 'viewer'] as $persona) {
            self::assertSame(200, $this->api($persona, 'GET', $this->uri.'/revisions'), $persona);
            self::assertSame(200, $this->api($persona, 'GET', $this->uri.'/revisions/1'), $persona);
            // A revision number that exists on a note they cannot see.
            self::assertSame(404, $this->api($persona, 'GET', $foreign.'/revisions'), $persona);
            self::assertSame(404, $this->api($persona, 'GET', $foreign.'/revisions/1'), $persona);
            self::assertSame(['error' => 'Note not found.'], $this->json());
        }
        self::assertSame(404, $this->api('unrelated', 'GET', $this->uri.'/revisions'));
        self::assertSame(404, $this->api('unrelated', 'GET', $this->uri.'/revisions/1'));
        self::assertSame(401, $this->api(null, 'GET', $this->uri.'/revisions'));
        self::assertSame(401, $this->api(null, 'GET', $this->uri.'/revisions/1', null, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]));

        foreach (['2', '0', 'abc', '1e0'] as $rev) {
            self::assertSame(404, $this->api('owner', 'GET', $this->uri.'/revisions/'.$rev), $rev);
            self::assertSame(['error' => 'Revision not found.'], $this->json());
        }
        foreach (['page=0', 'itemsPerPage=101', 'page=x'] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', $this->uri.'/revisions?'.$query), $query);
        }

        // Once the share is revoked the history is gone with the note.
        self::assertSame(204, $this->api('owner', 'DELETE', $this->uri.'/shares/'.$this->shareIdOf('viewer')));
        self::assertSame(404, $this->api('viewer', 'GET', $this->uri.'/revisions/1'));
    }

    public function testOnlyTheHundredLatestRevisionsAreKept(): void
    {
        for ($version = 1; $version <= 104; ++$version) {
            self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['content' => 'content '.($version + 1)], ['If-Match' => '"'.$version.'"']));
        }
        self::assertSame(105, $this->json()['version']);

        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/revisions?itemsPerPage=100'));
        self::assertSame([100, 105, 6], [$this->json()['total'], $this->json()['items'][0]['revision'], $this->json()['items'][99]['revision']]);
        self::assertSame(404, $this->api('owner', 'GET', $this->uri.'/revisions/5'));
        self::assertSame(404, $this->api('owner', 'POST', $this->uri.'/revisions/1/restore', null, ['If-Match' => '"105"']));
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/revisions/6'));
        self::assertSame('content 6', $this->json()['content']);
    }

    public function testDeletingANoteDeletesItsHistory(): void
    {
        self::assertSame(204, $this->api('owner', 'DELETE', $this->uri));
        self::assertSame(0, $this->em()->getRepository(NoteRevision::class)->count(['note' => $this->noteId]));
        self::assertSame(404, $this->api('owner', 'GET', $this->uri.'/revisions'));
    }

    public function testOpenApiDocumentsHistoryRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $doc = $this->json();

        self::assertArrayHasKey('patch', $doc['paths']['/api/notes/{id}']);
        self::assertArrayHasKey('get', $doc['paths']['/api/notes/{id}/revisions']);
        self::assertArrayHasKey('get', $doc['paths']['/api/notes/{id}/revisions/{rev}']);
        self::assertEqualsCanonicalizing(['200', '401', '403', '404', '412', '428', '500'], array_map('strval', array_keys($doc['paths']['/api/notes/{id}/revisions/{rev}/restore']['post']['responses'])));
        self::assertArrayHasKey('version', $doc['components']['schemas']['Note-note.read']['properties']);
    }

    private function etag(): ?string
    {
        return $this->client->getResponse()->headers->get('ETag');
    }

    private function shareIdOf(string $persona): string
    {
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/shares'));

        return array_column(array_map(static fn (array $s): array => ['email' => $s['user']['email'], 'id' => $s['id']], $this->json()['items']), 'id', 'email')[$this->email($persona)];
    }
}
