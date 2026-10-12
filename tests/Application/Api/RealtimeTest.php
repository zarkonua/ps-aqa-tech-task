<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Service\Realtime;

final class RealtimeTest extends ApiTestCase
{
    private string $uri;
    private string $noteUri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->noteUri = $this->noteUri('shared_1');
        $this->uri = '/api/realtime/notes/'.$this->noteIds['shared_1'];
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(Realtime::class)->forget(array_values($this->noteIds), array_values($this->userIds));

        parent::tearDown();
    }

    public function testStreamStartsWithTheCurrentStateAndReplaysWhatWasMissed(): void
    {
        $stream = $this->stream('owner');
        self::assertSame('text/event-stream; charset=utf-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringStartsWith("retry: 1000\n\n", $stream['raw']);
        self::assertSame(['connected'], array_column($stream['events'], 'event'));
        self::assertSame(
            ['note_id' => $this->noteIds['shared_1'], 'version' => 1, 'presence' => [['id' => $this->userIds['owner'], 'email' => $this->email('owner')]]],
            $stream['events'][0]['data'],
        );

        // The owner's own arrival is where they continue from.
        $bookmark = $this->poll('owner')['last_event_id'];
        self::assertSame(200, $this->api('editor', 'PATCH', $this->noteUri, ['content' => 'a very private new text'], ['If-Match' => '"1"']));
        self::assertSame(200, $this->api('editor', 'PATCH', $this->noteUri, ['title' => 'Renamed'], ['If-Match' => '"2"']));

        $stream = $this->stream('owner', $bookmark);
        self::assertSame(['connected', 'note.updated', 'note.updated'], array_column($stream['events'], 'event'));
        self::assertSame(3, $stream['events'][0]['data']['version']);
        $update = $stream['events'][1];
        self::assertEqualsCanonicalizing(['note_id', 'version', 'actor', 'created_at'], array_keys($update['data']));
        self::assertSame([2, ['id' => $this->userIds['editor'], 'email' => $this->email('editor')]], [$update['data']['version'], $update['data']['actor']]);
        self::assertSame(3, $stream['events'][2]['data']['version']);
        // Events say that something changed, not what the text is.
        self::assertStringNotContainsString('private new text', $stream['raw']);
        self::assertStringNotContainsString('Renamed', $stream['raw']);

        // Continuing after the last event received: nothing is sent twice.
        $again = $this->stream('owner', $stream['events'][2]['id']);
        self::assertSame(['connected'], array_column($again['events'], 'event'));
        $afterFirst = $this->stream('owner', $stream['events'][1]['id']);
        self::assertSame([$stream['events'][2]['id']], array_values(array_filter(array_column($afterFirst['events'], 'id'))));
    }

    public function testEventIdsAreOpaque(): void
    {
        $bookmark = $this->poll('owner')['last_event_id'];
        for ($version = 1; $version <= 3; ++$version) {
            self::assertSame(200, $this->api('owner', 'PATCH', $this->noteUri, ['content' => 'v'.$version], ['If-Match' => '"'.$version.'"']));
        }

        $ids = array_column($this->poll('owner', $bookmark)['events'], 'id');
        self::assertCount(3, $ids);
        self::assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        }
        $sorted = $ids;
        sort($sorted);
        // 1 in 6 that three random IDs happen to be ascending; the database IDs always are.
        self::assertStringNotContainsString((string) $this->em()->getConnection()->fetchOne('SELECT MAX(id) FROM realtime_events'), implode('', $ids));
    }

    public function testPollingGivesTheSameEvents(): void
    {
        $first = $this->poll('viewer');
        self::assertSame(['note_id', 'version', 'resync', 'events', 'last_event_id', 'presence'], array_keys($first));
        self::assertSame([$this->noteIds['shared_1'], 1, false, []], [$first['note_id'], $first['version'], $first['resync'], $first['events']]);
        self::assertSame([['id' => $this->userIds['viewer'], 'email' => $this->email('viewer')]], $first['presence']);
        self::assertNotNull($first['last_event_id'], 'the viewer joining is itself an event');

        self::assertSame(200, $this->api('owner', 'PATCH', $this->noteUri, ['content' => 'changed'], ['If-Match' => '"1"']));
        // Saving the same text again is not a change and not an event.
        self::assertSame(200, $this->api('owner', 'PATCH', $this->noteUri, ['content' => 'changed'], ['If-Match' => '"2"']));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->noteUri, ['content' => 'changed again'], ['If-Match' => '"2"']));
        // Restoring is an update as well.
        self::assertSame(200, $this->api('owner', 'POST', $this->noteUri.'/revisions/2/restore', null, ['If-Match' => '"3"']));

        $second = $this->poll('viewer', $first['last_event_id']);
        self::assertSame(4, $second['version']);
        self::assertSame(['note.updated', 'note.updated', 'note.updated'], array_column($second['events'], 'type'));
        self::assertSame(['id', 'type', 'data', 'created_at'], array_keys($second['events'][0]));
        self::assertSame([2, 3, 4], array_column(array_column($second['events'], 'data'), 'version'));
        self::assertSame($second['events'][2]['id'], $second['last_event_id']);

        // The bookmark moved: asking again with it gives nothing new, asking with the old one gives the same again.
        $third = $this->poll('viewer', $second['last_event_id']);
        self::assertSame([[], $second['last_event_id']], [$third['events'], $third['last_event_id']]);
        self::assertSame(array_column($second['events'], 'id'), array_column($this->poll('viewer', $first['last_event_id'])['events'], 'id'));
    }

    public function testUnknownBookmarkAsksForAResync(): void
    {
        $unknown = str_repeat('ab', 16);

        $poll = $this->poll('owner', $unknown);
        self::assertSame([true, []], [$poll['resync'], $poll['events']]);
        self::assertSame(1, $poll['version']);

        $stream = $this->stream('owner', $unknown);
        self::assertSame(['resync'], array_column($stream['events'], 'event'));
        self::assertSame(1, $stream['events'][0]['data']['version']);

        // A bookmark from another note is unknown here too.
        self::assertSame(200, $this->api('owner', 'GET', '/api/realtime/notes/'.$this->noteIds['private_1'].'/poll'));
        $other = $this->json()['last_event_id'];
        self::assertTrue($this->poll('owner', $other)['resync']);
        // Events older than an hour are dropped: their bookmarks expire with them.
        $bookmark = $this->poll('owner')['last_event_id'];
        $this->em()->getConnection()->executeStatement('UPDATE realtime_events SET created_at = ? WHERE note_id = ?', [gmdate('Y-m-d H:i:s', time() - 3700), $this->noteIds['shared_1']]);
        self::assertSame(200, $this->api('owner', 'PATCH', $this->noteUri, ['content' => 'later'], ['If-Match' => '"1"']));
        self::assertTrue($this->poll('owner', $bookmark)['resync']);
    }

    public function testPresenceJoinsLeavesAndExpires(): void
    {
        $owner = $this->poll('owner');
        $this->poll('editor');
        $this->stream('viewer');

        $state = $this->poll('owner', $owner['last_event_id']);
        self::assertSame(['presence.joined', 'presence.joined'], array_column($state['events'], 'type'));
        self::assertSame([$this->email('editor'), $this->email('viewer')], array_column(array_column(array_column($state['events'], 'data'), 'user'), 'email'));
        self::assertSame([$this->email('editor'), $this->email('owner'), $this->email('viewer')], array_column($state['presence'], 'email'));

        // Heartbeats of someone already present are not announced again.
        $this->poll('editor');
        self::assertSame([], $this->poll('owner', $state['last_event_id'])['events']);

        // Leaving explicitly.
        self::assertSame(204, $this->api('editor', 'DELETE', $this->uri.'/presence'));
        self::assertSame(204, $this->api('editor', 'DELETE', $this->uri.'/presence'));
        $state = $this->poll('owner', $state['last_event_id']);
        self::assertSame([['presence.left', $this->email('editor')]], array_map(static fn (array $e): array => [$e['type'], $e['data']['user']['email']], $state['events']));

        // Not heard from for more than 30 seconds: gone, and announced once.
        $connection = $this->em()->getConnection();
        $connection->executeStatement('UPDATE note_presence SET last_seen_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 29), $this->userIds['viewer']]);
        self::assertContains($this->email('viewer'), array_column($this->poll('owner', $state['last_event_id'])['presence'], 'email'));
        $connection->executeStatement('UPDATE note_presence SET last_seen_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 31), $this->userIds['viewer']]);
        $state = $this->poll('owner', $state['last_event_id']);
        self::assertSame([['presence.left', $this->email('viewer')]], array_map(static fn (array $e): array => [$e['type'], $e['data']['user']['email']], $state['events']));
        self::assertSame([$this->email('owner')], array_column($state['presence'], 'email'));
        self::assertSame([], $this->poll('owner', $state['last_event_id'])['events']);

        // Presence is per note.
        self::assertSame(200, $this->api('owner', 'GET', '/api/realtime/notes/'.$this->noteIds['shared_2'].'/poll'));
        self::assertSame([$this->email('owner')], array_column($this->json()['presence'], 'email'));
    }

    public function testRevokedReaderIsCutOff(): void
    {
        $viewer = $this->poll('viewer');
        $owner = $this->poll('owner');
        self::assertSame(204, $this->api('owner', 'DELETE', $this->sharesUri('shared_1', 'shared_1/viewer')));

        // The event for the revoked user exists, addressed to them alone…
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM realtime_events WHERE note_id = ? AND type = ?', [$this->noteIds['shared_1'], 'share.revoked']);
        self::assertSame($this->userIds['viewer'], $row['only_for_user_id']);
        // …the owner and the editor are told only that somebody left, not who lost access.
        self::assertSame(['presence.left'], array_column($this->poll('owner', $owner['last_event_id'])['events'], 'type'));
        self::assertNotContains('share.revoked', array_column($this->poll('editor')['events'], 'type'));

        // No new stream, no polling, no more events for the revoked reader.
        foreach (['/events', '/poll', '/poll?after='.$viewer['last_event_id']] as $path) {
            self::assertSame(404, $this->api('viewer', 'GET', $this->uri.$path), $path);
            self::assertSame(['error' => 'Note not found.'], $this->json());
        }
        self::assertSame(404, $this->api('viewer', 'DELETE', $this->uri.'/presence'));
        self::assertSame(200, $this->api('owner', 'PATCH', $this->noteUri, ['content' => 'after the revoke'], ['If-Match' => '"1"']));
        self::assertSame(404, $this->api('viewer', 'GET', $this->uri.'/poll?after='.$viewer['last_event_id']));
        self::assertNotContains($this->email('viewer'), array_column($this->poll('owner')['presence'], 'email'));
    }

    public function testDeletedNoteEndsEverything(): void
    {
        $this->poll('editor');
        self::assertSame(204, $this->api('owner', 'DELETE', $this->noteUri));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM realtime_events WHERE note_id = ? AND type = ?', [$this->noteIds['shared_1'], 'note.deleted']));
        foreach (['owner', 'editor'] as $persona) {
            self::assertSame(404, $this->api($persona, 'GET', $this->uri.'/events'), $persona);
            self::assertSame(404, $this->api($persona, 'GET', $this->uri.'/poll'), $persona);
        }

        // Deleting in bulk tells streams as well.
        self::assertSame(200, $this->api('owner', 'POST', '/api/notes/bulk', ['operation' => 'delete', 'ids' => [$this->noteIds['shared_2']]]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM realtime_events WHERE note_id = ? AND type = ?', [$this->noteIds['shared_2'], 'note.deleted']));
    }

    public function testAuthenticationIsByHeaderOnly(): void
    {
        $jwt = $this->token('owner');
        foreach (['/events', '/poll'] as $path) {
            self::assertSame(401, $this->api(null, 'GET', $this->uri.$path), $path);
            // A token in the URL is not a token.
            foreach (['token', 'access_token', 'bearer', 'jwt', 'authorization'] as $parameter) {
                self::assertSame(401, $this->api(null, 'GET', $this->uri.$path.'?'.$parameter.'='.$jwt), $parameter);
            }
            self::assertSame(401, $this->api(null, 'GET', $this->uri.$path, null, ['Authorization' => 'Bearer '.$this->token('owner', time() - 60)]), 'expired');
            self::assertSame(404, $this->api('unrelated', 'GET', $this->uri.$path), $path);
            self::assertSame(404, $this->api('owner', 'GET', '/api/realtime/notes/00000000-0000-4000-8000-000000000000'.$path));
            self::assertSame(404, $this->api('owner', 'GET', '/api/realtime/notes/nope'.$path));
        }

        foreach (['after=1', 'after=abc', 'after[]=x', 'after='.str_repeat('g', 32), 'after='.rawurlencode("' OR 1=1")] as $query) {
            self::assertSame(422, $this->api('owner', 'GET', $this->uri.'/poll?'.$query), $query);
        }
        // An unusable Last-Event-ID is treated like an unknown one: start over.
        $stream = $this->stream('owner', '<script>alert(1)</script>');
        self::assertSame(['resync'], array_column($stream['events'], 'event'));
        self::assertStringNotContainsString('<script>', $stream['raw']);
    }

    public function testOpenApiDocumentsRealtimeRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        self::assertArrayHasKey('text/event-stream', $paths['/api/realtime/notes/{id}/events']['get']['responses']['200']['content']);
        self::assertEqualsCanonicalizing(['200', '401', '404', '422', '500'], array_map('strval', array_keys($paths['/api/realtime/notes/{id}/poll']['get']['responses'])));
        self::assertArrayHasKey('delete', $paths['/api/realtime/notes/{id}/presence']);
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(string $persona, ?string $after = null): array
    {
        self::assertSame(200, $this->api($persona, 'GET', $this->uri.'/poll'.(null === $after ? '' : '?after='.$after)));

        return $this->json();
    }

    /**
     * Reads one pass of the event stream (in tests a stream answers once and ends).
     *
     * @return array{raw: string, events: list<array{id: string|null, event: string, data: array<string, mixed>}>}
     */
    private function stream(string $persona, ?string $lastEventId = null): array
    {
        ob_start();
        $status = $this->api($persona, 'GET', $this->uri.'/events', null, null === $lastEventId ? [] : ['Last-Event-ID' => $lastEventId]);
        $raw = (string) ob_get_clean();
        if ('' === $raw) {
            $raw = (string) $this->client->getInternalResponse()->getContent();
        }
        self::assertSame(200, $status, $raw);

        $events = [];
        foreach (explode("\n\n", trim($raw)) as $block) {
            $fields = [];
            foreach (explode("\n", $block) as $line) {
                [$name, $value] = array_pad(explode(': ', $line, 2), 2, '');
                $fields[$name] = $value;
            }
            if (isset($fields['event'])) {
                $events[] = ['id' => $fields['id'] ?? null, 'event' => $fields['event'], 'data' => json_decode($fields['data'] ?? '{}', true, 16, JSON_THROW_ON_ERROR)];
            }
        }

        return ['raw' => $raw, 'events' => $events];
    }
}
