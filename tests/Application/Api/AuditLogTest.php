<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AuditLogTest extends ApiTestCase
{
    private string $noteId;
    private string $uri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUser('admin');
        $admin = $this->em()->find(User::class, $this->userIds['admin']);
        $admin->setIsAdmin(true);
        $this->em()->flush();

        self::assertSame(201, $this->api('owner', 'POST', '/api/notes', ['title' => 'Audited', 'content' => 'first text'], ['X-Correlation-ID' => 'audit-test-create']));
        $this->noteId = $this->json()['id'];
        $this->uri = '/api/notes/'.$this->noteId;
    }

    protected function tearDown(): void
    {
        $this->em()->createQueryBuilder()->delete(AuditEvent::class, 'a')->where('a.noteId = :note')->setParameter('note', $this->noteId)->getQuery()->execute();

        parent::tearDown();
    }

    public function testOneEventPerEffectiveChangeAndNoneForNoOps(): void
    {
        self::assertSame(['note.created'], $this->types('owner'));

        // An edit, then the same edit again (which changes nothing).
        $secret = 'a very private sentence';
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['content' => $secret], ['If-Match' => '"1"', 'X-Correlation-ID' => 'audit-test-edit']));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['content' => $secret], ['If-Match' => '"2"']));
        self::assertSame(200, $this->api('owner', 'PUT', $this->uri, ['title' => 'Audited', 'content' => $secret]));

        // Share, same role again (no-op), role change, revoke.
        self::assertSame(201, $this->api('owner', 'POST', $this->uri.'/shares', ['email' => $this->email('editor'), 'role' => 'editor']));
        $share = $this->uri.'/shares/'.$this->json()['id'];
        self::assertSame(200, $this->api('owner', 'PATCH', $share, ['role' => 'editor']));
        self::assertSame(200, $this->api('owner', 'PATCH', $share, ['role' => 'viewer']));

        self::assertSame(['share.role_changed', 'share.granted', 'note.updated', 'note.created'], $this->types('owner'));

        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/activity'));
        $page = $this->json();
        self::assertSame(['items', 'next_cursor', 'limit'], array_keys($page));
        self::assertSame([null, 20], [$page['next_cursor'], $page['limit']]);

        $update = $page['items'][2];
        self::assertSame(['id', 'type', 'actor', 'target_user', 'note_id', 'subject_id', 'details', 'correlation_id', 'created_at'], array_keys($update));
        self::assertSame(['id' => $this->userIds['owner'], 'email' => $this->email('owner')], $update['actor']);
        self::assertSame([$this->noteId, 'audit-test-edit', null], [$update['note_id'], $update['correlation_id'], $update['target_user']]);
        // A summary of the change, not the text.
        self::assertEquals(['changed' => ['content'], 'content' => ['length_before' => 10, 'length_after' => strlen($secret)], 'version' => 2], $update['details']);
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $update['created_at']);

        $granted = $page['items'][1];
        self::assertSame(['id' => $this->userIds['editor'], 'email' => $this->email('editor')], $granted['target_user']);
        self::assertEquals(['role' => 'editor'], $granted['details']);
        self::assertEquals(['from' => 'editor', 'to' => 'viewer'], $page['items'][0]['details']);
        self::assertSame('audit-test-create', $page['items'][3]['correlation_id']);
    }

    public function testRefusedRequestsLeaveNoEvents(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $this->uri.'/shares', ['email' => $this->email('viewer'), 'role' => 'viewer']));
        $before = $this->types('owner');

        // Forbidden, invisible, invalid, stale and unauthenticated attempts.
        self::assertSame(403, $this->api('viewer', 'PUT', $this->uri, ['title' => 'x', 'content' => 'y']));
        self::assertSame(403, $this->api('viewer', 'DELETE', $this->uri));
        self::assertSame(403, $this->api('viewer', 'POST', $this->uri.'/shares', ['email' => $this->email('editor'), 'role' => 'viewer']));
        self::assertSame(404, $this->api('unrelated', 'PUT', $this->uri, ['title' => 'x', 'content' => 'y']));
        self::assertSame(404, $this->api('unrelated', 'DELETE', $this->uri));
        self::assertSame(422, $this->api('owner', 'PUT', $this->uri, ['title' => '', 'content' => 'y']));
        self::assertSame(412, $this->api('owner', 'PATCH', $this->uri, ['title' => 'x'], ['If-Match' => '"9"']));
        self::assertSame(409, $this->api('owner', 'POST', $this->uri.'/shares', ['email' => $this->email('viewer'), 'role' => 'editor']));
        self::assertSame(401, $this->api(null, 'DELETE', $this->uri));

        self::assertSame($before, $this->types('owner'));
        self::assertSame(0, $this->em()->getRepository(AuditEvent::class)->count(['actorId' => [$this->userIds['viewer'], $this->userIds['unrelated']]]));
    }

    public function testActivityIsForCurrentReadersAndHidesSharingFromNonOwners(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $this->uri.'/shares', ['email' => $this->email('viewer'), 'role' => 'viewer']));
        $viewerShare = $this->uri.'/shares/'.$this->json()['id'];
        self::assertSame(201, $this->api('owner', 'POST', $this->uri.'/shares', ['email' => $this->email('editor'), 'role' => 'editor']));
        self::assertSame(200, $this->api('editor', 'PATCH', $this->uri, ['title' => 'Edited'], ['If-Match' => '"1"']));
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'Private task', 'note_id' => $this->noteId]));

        self::assertSame(['note.updated', 'share.granted', 'share.granted', 'note.created'], $this->types('owner'));
        // Who else the note is shared with is the owner's business; tasks are their owner's.
        foreach (['viewer', 'editor'] as $persona) {
            self::assertSame(['note.updated', 'note.created'], $this->types($persona), $persona);
        }
        self::assertStringNotContainsString($this->email('viewer'), (string) $this->client->getResponse()->getContent(), 'the editor does not learn who else has access');
        self::assertSame(200, $this->api('viewer', 'GET', $this->uri.'/activity'));
        self::assertSame($this->email('editor'), $this->json()['items'][0]['actor']['email'], 'who edited is visible to readers');

        self::assertSame(404, $this->api('unrelated', 'GET', $this->uri.'/activity'));
        self::assertSame(['error' => 'Note not found.'], $this->json());
        self::assertSame(404, $this->api('owner', 'GET', '/api/notes/00000000-0000-4000-8000-000000000000/activity'));
        self::assertSame(404, $this->api('owner', 'GET', '/api/notes/nope/activity'));
        self::assertSame(401, $this->api(null, 'GET', $this->uri.'/activity'));
        self::assertSame(401, $this->api(null, 'GET', $this->uri.'/activity', null, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]));

        // Revoked: no longer a reader.
        self::assertSame(204, $this->api('owner', 'DELETE', $viewerShare));
        self::assertSame(404, $this->api('viewer', 'GET', $this->uri.'/activity'));

        // Deleted: gone for everyone but the administrator's feed.
        self::assertSame(204, $this->api('owner', 'DELETE', $this->uri));
        foreach (['owner', 'editor'] as $persona) {
            self::assertSame(404, $this->api($persona, 'GET', $this->uri.'/activity'), $persona);
        }
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?noteId='.$this->noteId.'&type=note.'));
        self::assertSame(['note.deleted', 'note.updated', 'note.created'], array_column($this->json()['items'], 'type'));
    }

    public function testFullFeedIsForAdministratorsOnly(): void
    {
        foreach (['owner', 'editor', 'viewer', 'unrelated'] as $persona) {
            self::assertSame(403, $this->api($persona, 'GET', '/api/audit-events'), $persona);
            self::assertSame(['error' => 'Only administrators can read the audit feed.'], $this->json());
            self::assertSame(403, $this->api($persona, 'GET', '/api/audit-events?actorId='.$this->userIds[$persona]), 'not even their own events');
        }
        self::assertSame(401, $this->api(null, 'GET', '/api/audit-events'));

        self::assertSame(200, $this->api('owner', 'GET', '/api/account/profile'));
        self::assertFalse($this->json()['is_admin']);
        self::assertSame(200, $this->api('admin', 'GET', '/api/account/profile'));
        self::assertTrue($this->json()['is_admin']);

        self::assertSame(201, $this->api('owner', 'POST', '/api/tags', ['name' => 'Audited tag'], ['X-Correlation-ID' => 'audit-test-tag']));
        $tagId = $this->json()['id'];
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/account/settings', ['timezone' => 'Europe/Kyiv']));

        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?actorId='.$this->userIds['owner']));
        self::assertSame(['account.settings_updated', 'tag.created', 'note.created'], array_column($this->json()['items'], 'type'));
        self::assertSame([$tagId, null], [$this->json()['items'][1]['subject_id'], $this->json()['items'][1]['note_id']]);

        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?correlationId=audit-test-tag'));
        self::assertSame(['tag.created'], array_column($this->json()['items'], 'type'));
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?type=tag.created&actorId='.$this->userIds['owner']));
        self::assertCount(1, $this->json()['items']);
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?type=account.&actorId='.$this->userIds['owner']));
        self::assertSame(['account.settings_updated'], array_column($this->json()['items'], 'type'));
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?noteId='.$this->noteId));
        self::assertSame(['note.created'], array_column($this->json()['items'], 'type'));

        foreach (['actorId=me', 'noteId[]=x', 'type=share', 'type='.rawurlencode("share.' OR 1=1"), 'type[]=a.b', 'correlationId=x', 'limit=0', 'limit=101', 'limit=abc'] as $query) {
            self::assertSame(422, $this->api('admin', 'GET', '/api/audit-events?'.$query), $query);
        }

        // The role is checked on every request: taking it away works at once, with the same token.
        $tester = new CommandTester((new Application(self::$kernel))->find('app:user:admin'));
        self::assertSame(0, $tester->execute(['email' => $this->email('admin'), '--revoke' => true]));
        self::assertSame(403, $this->api('admin', 'GET', '/api/audit-events'));
        self::assertSame(0, $tester->execute(['email' => strtoupper($this->email('admin'))]));
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?limit=1'));
        self::assertSame(1, $tester->execute(['email' => 'nobody@api-tests.test']));
    }

    public function testCursorPagingIsStable(): void
    {
        // Twelve more events, several within the same second.
        for ($version = 1; $version <= 12; ++$version) {
            self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['content' => 'text '.$version], ['If-Match' => '"'.$version.'"']));
        }

        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/activity?limit=100'));
        $all = array_column($this->json()['items'], 'id');
        self::assertCount(13, $all);
        self::assertNull($this->json()['next_cursor']);

        $paged = [];
        $cursor = null;
        $pages = 0;
        do {
            self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/activity?limit=5'.(null === $cursor ? '' : '&cursor='.$cursor)));
            array_push($paged, ...array_column($this->json()['items'], 'id'));
            $cursor = $this->json()['next_cursor'];
            ++$pages;

            // Something new happens while the client is paging: the pages already planned do not shift.
            if (1 === $pages) {
                self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'Changed while paging'], ['If-Match' => '"13"']));
            }
        } while (null !== $cursor && $pages < 10);

        self::assertSame(3, $pages);
        self::assertSame($all, $paged, 'no event twice, none skipped');

        // The same cursor always gives the same page.
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/activity?limit=5'));
        $first = $this->json();
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/activity?limit=5&cursor='.$first['next_cursor']));
        $again = $this->json();
        self::assertSame(200, $this->api('owner', 'GET', $this->uri.'/activity?limit=5&cursor='.$first['next_cursor']));
        self::assertSame($again, $this->json());

        // The administrator's feed pages the same way.
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?limit=4&noteId='.$this->noteId));
        self::assertNotNull($this->json()['next_cursor']);
        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?limit=4&noteId='.$this->noteId.'&cursor='.$this->json()['next_cursor']));
        self::assertCount(4, $this->json()['items']);

        foreach (['', 'abc', '!!!', 'bm90LWEtY3Vyc29y', base64_encode('123|not-a-uuid'), base64_encode('-5|00000000-0000-4000-8000-000000000000'), base64_encode('1 OR 1=1|00000000-0000-4000-8000-000000000000'), base64_encode('12'), str_repeat('A', 200), rawurlencode("' OR 1=1")] as $bad) {
            self::assertSame(400, $this->api('owner', 'GET', $this->uri.'/activity?cursor='.$bad), $bad);
            self::assertSame(['error' => 'Invalid cursor.'], $this->json());
        }
        self::assertSame(400, $this->api('owner', 'GET', $this->uri.'/activity?cursor[]=x'));
        self::assertSame(422, $this->api('owner', 'GET', $this->uri.'/activity?limit=0'));
    }

    public function testCredentialsNeverReachTheAuditTable(): void
    {
        self::assertEquals(
            ['role' => 'viewer', 'nested' => ['kept' => 1]],
            AuditLogger::withoutSecrets(['role' => 'viewer', 'password' => 'p', 'new_password' => 'p', 'reset_token' => 't', 'Authorization' => 'Bearer x', 'jwt' => 'x', 'nested' => ['kept' => 1, 'api_secret' => 's', 'accessToken' => 't']]),
        );

        // Through the API: a password change and a reset leave events, without the credentials.
        self::assertSame(200, $this->api('owner', 'POST', '/api/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'a-new-long-passphrase']));
        $jwt = $this->json()['token'];
        self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $this->email('owner')]));
        preg_match('/reset_token=([a-f0-9]{64})/', self::getMailerMessage()->getTextBody(), $m);

        self::assertSame(200, $this->api('admin', 'GET', '/api/audit-events?limit=100&actorId='.$this->userIds['owner']));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertContains('account.password_changed', array_column($this->json()['items'], 'type'));
        self::assertContains('account.password_reset_requested', array_column($this->json()['items'], 'type'));
        foreach ([self::PASSWORD, 'a-new-long-passphrase', $jwt, $m[1], hash('sha256', $m[1])] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
    }

    public function testRetentionCommand(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE audit_events SET created_at = ? WHERE note_id = ?', [gmdate('Y-m-d H:i:s', time() - 400 * 86400), $this->noteId]);
        self::assertSame(200, $this->api('owner', 'PATCH', $this->uri, ['title' => 'Recent'], ['If-Match' => '"1"']));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:audit:prune'));
        self::assertSame(2, $tester->execute(['--days' => 'soon']));
        self::assertSame(0, $tester->execute([]));
        self::assertMatchesRegularExpression('/Removed \d+ audit record\(s\) older than 365 days\./', $tester->getDisplay());

        self::assertSame(['note.updated'], $this->types('owner'));
    }

    public function testOpenApiDocumentsAuditRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        self::assertEqualsCanonicalizing(['200', '400', '401', '404', '422', '500'], array_map('strval', array_keys($paths['/api/notes/{id}/activity']['get']['responses'])));
        self::assertEqualsCanonicalizing(['200', '400', '401', '403', '422', '500'], array_map('strval', array_keys($paths['/api/audit-events']['get']['responses'])));
        self::assertArrayHasKey('AuditEvent', $this->json()['components']['schemas']);
    }

    /**
     * @return list<string> event types in the note's activity as the persona sees it, newest first
     */
    private function types(string $persona): array
    {
        self::assertSame(200, $this->api($persona, 'GET', $this->uri.'/activity?limit=100'));

        return array_column($this->json()['items'], 'type');
    }
}
