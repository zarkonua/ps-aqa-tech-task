<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\NoteShare;
use PHPUnit\Framework\Attributes\DataProvider;

final class NoteSharingTest extends ApiTestCase
{
    public function testOwnerSharesNoteWithViewerAndEditor(): void
    {
        $status = $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('viewer'), 'role' => 'viewer'], ['X-Correlation-ID' => 'test-correlation-1']);
        self::assertSame(201, $status);
        $share = $this->json();

        self::assertSame(['id', 'note_id', 'user', 'role', 'version', 'created_at', 'updated_at'], array_keys($share));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $share['id']);
        self::assertSame($this->noteIds['private_1'], $share['note_id']);
        self::assertSame(['id' => $this->userIds['viewer'], 'email' => $this->email('viewer')], $share['user']);
        self::assertSame('viewer', $share['role']);
        self::assertSame(1, $share['version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $share['created_at']);
        self::assertSame(
            $this->sharesUri('private_1').'/'.$share['id'],
            $this->client->getResponse()->headers->get('Location'),
        );
        self::assertSame('test-correlation-1', $this->client->getResponse()->headers->get('X-Correlation-ID'));

        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => strtoupper($this->email('editor')), 'role' => 'editor']));
        self::assertSame('editor', $this->json()['role']);

        self::assertSame(200, $this->api('viewer', 'GET', $this->noteUri('private_1')));
        self::assertSame('viewer', $this->json()['role']);
        self::assertSame(200, $this->api('editor', 'GET', $this->noteUri('private_1')));
        self::assertSame('editor', $this->json()['role']);

        $events = $this->auditEvents('private_1', AuditEvent::SHARE_GRANTED);
        self::assertCount(2, $events);
        $event = array_values(array_filter($events, static fn (AuditEvent $e): bool => $e->getShareId() === $share['id']))[0];
        self::assertSame($this->userIds['owner'], $event->getActorId());
        self::assertSame($this->userIds['viewer'], $event->getTargetUserId());
        self::assertSame(['role' => 'viewer'], $event->getMetadata());
        self::assertSame('test-correlation-1', $event->getCorrelationId());
    }

    public function testViewerIsReadOnly(): void
    {
        self::assertSame(200, $this->api('viewer', 'GET', $this->noteUri('shared_1')));
        $note = $this->json();
        self::assertSame('viewer', $note['role']);
        self::assertSame($this->userIds['owner'], $note['owner_id']);
        self::assertSame($this->email('owner'), $note['owner_email']);

        self::assertSame(403, $this->api('viewer', 'PUT', $this->noteUri('shared_1'), ['title' => 'changed', 'content' => 'changed']));
        self::assertSame(403, $this->api('viewer', 'DELETE', $this->noteUri('shared_1')));

        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('shared_1')));
        self::assertSame($this->runId.' shared_1', $this->json()['title']);
    }

    public function testEditorCanEditButNotDeleteAndOwnershipIsKept(): void
    {
        self::assertSame(200, $this->api('editor', 'PUT', $this->noteUri('shared_1'), ['title' => 'edited by editor', 'content' => 'new content']));
        $note = $this->json();
        self::assertSame('edited by editor', $note['title']);
        self::assertSame('editor', $note['role']);
        self::assertSame($this->userIds['owner'], $note['owner_id']);

        self::assertSame(403, $this->api('editor', 'DELETE', $this->noteUri('shared_1')));

        // Still the owner's note: listed as owned for the owner, never for the editor.
        self::assertSame(200, $this->api('owner', 'GET', $this->noteUri('shared_1')));
        self::assertSame('owner', $this->json()['role']);
        self::assertSame('new content', $this->json()['content']);
        self::assertSame(200, $this->api('editor', 'GET', '/api/notes?scope=owned&itemsPerPage=50'));
        self::assertSame([], $this->listedNoteKeys());
        // The viewer's share survived the edit.
        self::assertSame(200, $this->api('viewer', 'GET', $this->noteUri('shared_1')));
    }

    /**
     * @return iterable<string, array{string, string, string|null, array<string, mixed>|null}>
     */
    public static function shareManagementRequests(): iterable
    {
        yield 'list' => ['GET', 'shared_1', null, null];
        yield 'grant' => ['POST', 'shared_1', null, ['email' => 'someone@example.com', 'role' => 'viewer']];
        yield 'change role' => ['PATCH', 'shared_1', 'shared_1/viewer', ['role' => 'editor']];
        yield 'revoke' => ['DELETE', 'shared_1', 'shared_1/viewer', null];
    }

    #[DataProvider('shareManagementRequests')]
    public function testOnlyOwnerManagesShares(string $method, string $noteKey, ?string $shareKey, ?array $body): void
    {
        $uri = $this->sharesUri($noteKey, $shareKey);

        foreach (['editor', 'viewer'] as $persona) {
            self::assertSame(403, $this->api($persona, $method, $uri, $body), "$persona must be forbidden");
            self::assertSame(['error' => 'Only the note owner can manage sharing.'], $this->json());
        }

        // A user without access cannot tell the note exists, let alone enumerate its shares.
        self::assertSame(404, $this->api('unrelated', $method, $uri, $body));
        self::assertSame(['error' => 'Note not found.'], $this->json());

        self::assertSame(401, $this->api(null, $method, $uri, $body));

        self::assertCount(2, $this->em()->getRepository(NoteShare::class)->findBy(['note' => $this->noteIds['shared_1']]));
        self::assertSame('viewer', $this->em()->find(NoteShare::class, $this->shareIds['shared_1/viewer'])->getRole()->value);
    }

    public function testOwnerListsSharesWithPagination(): void
    {
        self::assertSame(200, $this->api('owner', 'GET', $this->sharesUri('shared_1')));
        $list = $this->json();
        self::assertSame(2, $list['total']);
        self::assertSame(1, $list['page']);
        self::assertSame(50, $list['items_per_page']);
        $emails = array_column(array_column($list['items'], 'user'), 'email');
        sort($emails);
        self::assertSame([$this->email('editor'), $this->email('viewer')], $emails);

        self::assertSame(200, $this->api('owner', 'GET', $this->sharesUri('shared_1').'?page=2&itemsPerPage=1'));
        self::assertCount(1, $this->json()['items']);
        self::assertSame(2, $this->json()['total']);

        self::assertSame(200, $this->api('owner', 'GET', $this->sharesUri('private_1')));
        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'items_per_page' => 50], $this->json());

        foreach (['page=0', 'page=abc', 'itemsPerPage=0', 'itemsPerPage=101', 'page[]=1'] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', $this->sharesUri('shared_1').'?'.$query), $query);
        }
    }

    public function testDuplicateShareIsRejected(): void
    {
        self::assertSame(409, $this->api('owner', 'POST', $this->sharesUri('shared_1'), ['email' => $this->email('viewer'), 'role' => 'editor']));
        self::assertSame(['error' => 'Note is already shared with this user.'], $this->json());
        self::assertSame('viewer', $this->em()->find(NoteShare::class, $this->shareIds['shared_1/viewer'])->getRole()->value);
        self::assertSame([], $this->auditEvents('shared_1'));
    }

    public function testUnknownOrUnconfirmedUserIsNotFound(): void
    {
        self::assertSame(404, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => 'nobody.'.$this->runId.'@api-tests.test', 'role' => 'viewer']));
        self::assertSame(['error' => 'User not found.'], $this->json());

        $this->createUser('pending', false);
        self::assertSame(404, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('pending'), 'role' => 'viewer']));
    }

    public function testEmailLookupsThroughSharingAreThrottled(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(404, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => sprintf('guess%d.%s@api-tests.test', $i, $this->runId), 'role' => 'viewer']));
        }
        self::assertSame(429, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('viewer'), 'role' => 'viewer']));
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame([], $this->em()->getRepository(NoteShare::class)->findBy(['note' => $this->noteIds['private_1']]));

        // Per user, and only for granting.
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/notes', ['title' => 't', 'content' => 'c']));
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/notes/'.$this->json()['id'].'/shares', ['email' => $this->email('viewer'), 'role' => 'viewer']));
        self::assertSame(200, $this->api('owner', 'GET', $this->sharesUri('private_1')));
    }

    public function testNoteCannotBeSharedWithItsOwner(): void
    {
        self::assertSame(422, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('owner'), 'role' => 'editor']));
        self::assertSame('email', $this->json()['violations'][0]['propertyPath']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int, string|null}>
     */
    public static function invalidGrantPayloads(): iterable
    {
        yield 'malformed JSON' => ['{"email": "a@example.com", "role":', 400, null];
        yield 'JSON list' => ['["viewer"]', 400, null];
        yield 'JSON scalar' => ['"viewer"', 400, null];
        yield 'empty body' => ['', 422, 'email'];
        yield 'missing email' => [['role' => 'viewer'], 422, 'email'];
        yield 'missing role' => [['email' => 'a@example.com'], 422, 'role'];
        yield 'null email' => [['email' => null, 'role' => 'viewer'], 422, 'email'];
        yield 'null role' => [['email' => 'a@example.com', 'role' => null], 422, 'role'];
        yield 'email type mismatch' => [['email' => ['a@example.com'], 'role' => 'viewer'], 422, 'email'];
        yield 'role type mismatch' => [['email' => 'a@example.com', 'role' => 1], 422, 'role'];
        yield 'blank email' => [['email' => '   ', 'role' => 'viewer'], 422, 'email'];
        yield 'invalid email' => [['email' => 'not-an-email', 'role' => 'viewer'], 422, 'email'];
        yield 'email too long' => [['email' => str_repeat('a', 180).'@example.com', 'role' => 'viewer'], 422, 'email'];
        yield 'XSS email' => [['email' => '<script>alert(1)</script>@example.com', 'role' => 'viewer'], 422, 'email'];
        yield 'unicode email' => [['email' => 'пользователь@пример.рф', 'role' => 'viewer'], 422, 'email'];
        yield 'unknown role' => [['email' => 'a@example.com', 'role' => 'admin'], 422, 'role'];
        yield 'owner role' => [['email' => 'a@example.com', 'role' => 'owner'], 422, 'role'];
        yield 'role wrong case' => [['email' => 'a@example.com', 'role' => 'Viewer'], 422, 'role'];
        yield 'unexpected field' => [['email' => 'a@example.com', 'role' => 'viewer', 'note_id' => 'x'], 422, 'note_id'];
    }

    #[DataProvider('invalidGrantPayloads')]
    public function testGrantValidation(array|string $body, int $expectedStatus, ?string $expectedField): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'POST', $this->sharesUri('private_1'), $body));

        $response = $this->json();
        self::assertIsString($response['error']);
        if (null !== $expectedField) {
            self::assertContains($expectedField, array_column($response['violations'], 'propertyPath'));
        }
        self::assertStringNotContainsString('<script>', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->em()->getRepository(NoteShare::class)->findBy(['note' => $this->noteIds['private_1']]));
    }

    public function testOwnerChangesRole(): void
    {
        $uri = $this->sharesUri('shared_1', 'shared_1/viewer');

        self::assertSame(403, $this->api('viewer', 'PUT', $this->noteUri('shared_1'), ['title' => 't', 'content' => 'c']));

        self::assertSame(200, $this->api('owner', 'PATCH', $uri, ['role' => 'editor', 'version' => 1]));
        $share = $this->json();
        self::assertSame('editor', $share['role']);
        self::assertSame(2, $share['version']);
        self::assertSame($this->shareIds['shared_1/viewer'], $share['id']);

        // Takes effect on the very next request.
        self::assertSame(200, $this->api('viewer', 'PUT', $this->noteUri('shared_1'), ['title' => 't', 'content' => 'c']));

        $events = $this->auditEvents('shared_1', AuditEvent::SHARE_ROLE_CHANGED);
        self::assertCount(1, $events);
        self::assertEquals(['from' => 'viewer', 'to' => 'editor'], $events[0]->getMetadata());

        // Setting the same role again changes nothing.
        self::assertSame(200, $this->api('owner', 'PATCH', $uri, ['role' => 'editor']));
        self::assertSame(2, $this->json()['version']);
        self::assertCount(1, $this->auditEvents('shared_1', AuditEvent::SHARE_ROLE_CHANGED));
    }

    public function testStaleVersionIsAConflict(): void
    {
        $uri = $this->sharesUri('shared_1', 'shared_1/viewer');
        self::assertSame(200, $this->api('owner', 'PATCH', $uri, ['role' => 'editor', 'version' => 1]));

        // A second client still holding version 1.
        self::assertSame(409, $this->api('owner', 'PATCH', $uri, ['role' => 'viewer', 'version' => 1]));
        self::assertSame('editor', $this->em()->find(NoteShare::class, $this->shareIds['shared_1/viewer'])->getRole()->value);

        self::assertSame(200, $this->api('owner', 'PATCH', $uri, ['role' => 'viewer', 'version' => 2]));
        self::assertSame(3, $this->json()['version']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int}>
     */
    public static function invalidRoleChangePayloads(): iterable
    {
        yield 'malformed JSON' => ['{role', 400];
        yield 'missing role' => [['version' => 1], 422];
        yield 'null role' => [['role' => null], 422];
        yield 'unknown role' => [['role' => 'owner'], 422];
        yield 'version as string' => [['role' => 'editor', 'version' => '1'], 422];
        yield 'version zero' => [['role' => 'editor', 'version' => 0], 422];
        yield 'version as bool' => [['role' => 'editor', 'version' => true], 422];
        yield 'unexpected field' => [['role' => 'editor', 'user_id' => 'x'], 422];
    }

    #[DataProvider('invalidRoleChangePayloads')]
    public function testRoleChangeValidation(array|string $body, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $this->api('owner', 'PATCH', $this->sharesUri('shared_1', 'shared_1/viewer'), $body));
        self::assertSame('viewer', $this->em()->find(NoteShare::class, $this->shareIds['shared_1/viewer'])->getRole()->value);
    }

    public function testShareIdMustBelongToTheNote(): void
    {
        // A real share ID, but of another note.
        $uri = $this->sharesUri('shared_2').'/'.$this->shareIds['shared_1/editor'];
        self::assertSame(404, $this->api('owner', 'PATCH', $uri, ['role' => 'viewer']));
        self::assertSame(404, $this->api('owner', 'DELETE', $uri));

        self::assertSame(404, $this->api('owner', 'DELETE', $this->sharesUri('shared_1').'/not-a-uuid'));
        self::assertSame(404, $this->api('owner', 'DELETE', $this->sharesUri('shared_1').'/00000000-0000-4000-8000-000000000000'));
        self::assertSame(404, $this->api('owner', 'GET', '/api/notes/00000000-0000-4000-8000-000000000000/shares'));
        self::assertNotNull($this->em()->find(NoteShare::class, $this->shareIds['shared_1/editor']));
    }

    public function testRevocationTakesEffectImmediately(): void
    {
        self::assertSame(200, $this->api('editor', 'GET', $this->noteUri('shared_1')));

        self::assertSame(204, $this->api('owner', 'DELETE', $this->sharesUri('shared_1', 'shared_1/editor')));
        self::assertSame('', $this->client->getResponse()->getContent());

        // Same token, next request: the note is gone for the former editor.
        self::assertSame(404, $this->api('editor', 'GET', $this->noteUri('shared_1')));
        self::assertSame(404, $this->api('editor', 'PUT', $this->noteUri('shared_1'), ['title' => 't', 'content' => 'c']));
        self::assertSame(200, $this->api('editor', 'GET', '/api/notes?scope=all'));
        self::assertSame([], $this->listedNoteKeys());

        // The other share is untouched, and revoking twice is a 404.
        self::assertSame(200, $this->api('viewer', 'GET', $this->noteUri('shared_1')));
        self::assertSame(404, $this->api('owner', 'DELETE', $this->sharesUri('shared_1', 'shared_1/editor')));

        $events = $this->auditEvents('shared_1', AuditEvent::SHARE_REVOKED);
        self::assertCount(1, $events);
        self::assertSame($this->userIds['editor'], $events[0]->getTargetUserId());

        // The user can be granted access again.
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('shared_1'), ['email' => $this->email('editor'), 'role' => 'viewer']));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function scopes(): iterable
    {
        yield 'owner default' => ['owner', '', ['private_1', 'private_2', 'shared_1', 'shared_2']];
        yield 'owner owned' => ['owner', 'scope=owned', ['private_1', 'private_2', 'shared_1', 'shared_2']];
        yield 'owner shared' => ['owner', 'scope=shared', []];
        yield 'owner all' => ['owner', 'scope=all', ['private_1', 'private_2', 'shared_1', 'shared_2']];
        yield 'viewer default' => ['viewer', '', []];
        yield 'viewer shared' => ['viewer', 'scope=shared', ['shared_1', 'shared_2']];
        yield 'viewer all' => ['viewer', 'scope=all', ['shared_1', 'shared_2']];
        yield 'editor owned' => ['editor', 'scope=owned', []];
        yield 'editor shared' => ['editor', 'scope=shared', ['shared_1']];
        yield 'unrelated all' => ['unrelated', 'scope=all', []];
        yield 'viewer search does not leak private notes' => ['viewer', 'scope=all&q=private', []];
        yield 'viewer search within shared' => ['viewer', 'scope=shared&q=shared_2', ['shared_2']];
    }

    #[DataProvider('scopes')]
    public function testScopeFilterRespectsAccess(string $persona, string $query, array $expectedNoteKeys): void
    {
        self::assertSame(200, $this->api($persona, 'GET', '/api/notes?itemsPerPage=50&'.$query));
        self::assertSame($expectedNoteKeys, $this->listedNoteKeys());
    }

    public function testScopeAllMixesOwnedAndSharedWithRoles(): void
    {
        // The editor owns one note of their own and has one shared note.
        self::assertSame(201, $this->api('editor', 'POST', '/api/notes', ['title' => $this->runId.' mine', 'content' => 'c']));
        $ownId = $this->json()['id'];
        self::assertSame('owner', $this->json()['role']);

        self::assertSame(200, $this->api('editor', 'GET', '/api/notes?scope=all&itemsPerPage=50'));
        $roles = array_column($this->json(), 'role', 'id');
        self::assertSame(['owner', 'editor'], [$roles[$ownId], $roles[$this->noteIds['shared_1']]]);
        self::assertCount(2, $roles);

        self::assertSame(200, $this->api('editor', 'GET', '/api/notes?scope=all&itemsPerPage=1&page=2'));
        self::assertCount(1, $this->json());
    }

    public function testInvalidScopeIsRejected(): void
    {
        self::assertSame(422, $this->api('owner', 'GET', '/api/notes?scope=everything'));
        self::assertSame('scope', $this->json()['violations'][0]['propertyPath']);
    }

    public function testDeletingNoteRemovesItsShares(): void
    {
        self::assertSame(204, $this->api('owner', 'DELETE', $this->noteUri('shared_1')));

        self::assertNull($this->em()->find(NoteShare::class, $this->shareIds['shared_1/viewer']));
        self::assertNull($this->em()->find(NoteShare::class, $this->shareIds['shared_1/editor']));
        self::assertNotNull($this->em()->find(NoteShare::class, $this->shareIds['shared_2/viewer']));

        self::assertSame(404, $this->api('viewer', 'GET', $this->noteUri('shared_1')));
        self::assertSame(404, $this->api('owner', 'GET', $this->sharesUri('shared_1')));
        self::assertSame(200, $this->api('viewer', 'GET', '/api/notes?scope=shared'));
        self::assertSame(['shared_2'], $this->listedNoteKeys());

        self::assertCount(2, $this->auditEvents('shared_1', AuditEvent::SHARE_REMOVED_WITH_NOTE));
    }

    public function testExpiredAndInvalidTokensAreRejected(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)];
        self::assertSame(401, $this->api(null, 'GET', $this->sharesUri('shared_1'), null, $headers));
        self::assertSame(401, $this->api(null, 'GET', '/api/notes?scope=shared', null, $headers));

        self::assertSame(401, $this->api(null, 'POST', $this->sharesUri('shared_1'), ['email' => $this->email('unrelated'), 'role' => 'viewer'], ['Authorization' => 'Bearer not.a.token']));
        self::assertNull($this->em()->getRepository(NoteShare::class)->findOneBy(['note' => $this->noteIds['shared_1'], 'user' => $this->userIds['unrelated']]));
    }

    public function testCorrelationIdIsGeneratedWhenMissingOrMalformed(): void
    {
        $this->api('owner', 'GET', $this->sharesUri('shared_1'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $this->client->getResponse()->headers->get('X-Correlation-ID'));

        $this->api('owner', 'GET', $this->sharesUri('shared_1'), null, ['X-Correlation-ID' => "bad id\twith spaces"]);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $this->client->getResponse()->headers->get('X-Correlation-ID'));
    }

    public function testNotesCrudStillWorksForOwner(): void
    {
        self::assertSame(201, $this->api('unrelated', 'POST', '/api/notes', ['title' => $this->runId.' own', 'content' => 'body']));
        $id = $this->json()['id'];
        self::assertSame(200, $this->api('unrelated', 'PUT', '/api/notes/'.$id, ['title' => $this->runId.' renamed', 'content' => 'body 2']));
        self::assertSame($this->userIds['unrelated'], $this->json()['owner_id']);
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notes?q='.$this->runId));
        self::assertSame([$id], array_column($this->json(), 'id'));
        self::assertSame(422, $this->api('unrelated', 'PUT', '/api/notes/'.$id, ['title' => '', 'content' => 'x']));
        self::assertSame(204, $this->api('unrelated', 'DELETE', '/api/notes/'.$id));
        self::assertSame(404, $this->api('unrelated', 'GET', '/api/notes/'.$id));
    }

    public function testOpenApiDocumentsSharingRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $doc = $this->json();

        $collection = $doc['paths']['/api/notes/{id}/shares'];
        $item = $doc['paths']['/api/notes/{id}/shares/{shareId}'];
        self::assertEqualsCanonicalizing(['201', '400', '404', '409', '422', '429', '401', '403', '500'], array_map('strval', array_keys($collection['post']['responses'])));
        self::assertEqualsCanonicalizing(['200', '404', '422', '401', '403', '500'], array_map('strval', array_keys($collection['get']['responses'])));
        self::assertArrayHasKey('409', $item['patch']['responses']);
        self::assertArrayHasKey('204', $item['delete']['responses']);
        self::assertArrayHasKey('NoteShare', $doc['components']['schemas']);

        $scope = array_values(array_filter($doc['paths']['/api/notes']['get']['parameters'], static fn (array $p): bool => 'scope' === $p['name']));
        self::assertSame(['owned', 'shared', 'all'], $scope[0]['schema']['enum']);
        self::assertArrayHasKey('role', $doc['components']['schemas']['Note-note.read']['properties']);
    }
}
