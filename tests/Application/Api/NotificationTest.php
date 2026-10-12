<?php

declare(strict_types=1);

namespace App\Tests\Application\Api;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notifier;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class NotificationTest extends ApiTestCase
{
    public function testShareTriggersANoticeAndCountDecreasesOnRead(): void
    {
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        self::assertSame(['items' => [], 'total' => 0, 'unread' => 0, 'page' => 1, 'items_per_page' => 20], $this->json());

        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('unrelated'), 'role' => 'viewer']));
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_2'), ['email' => $this->email('unrelated'), 'role' => 'editor']));

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications/unread-count'));
        self::assertSame(['unread' => 2], $this->json());

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        $list = $this->json();
        self::assertSame([2, 2], [$list['total'], $list['unread']]);
        $first = $list['items'][0];
        self::assertSame(['id', 'type', 'message', 'actor', 'resource', 'link', 'created_at', 'read_at'], array_keys($first));
        $byNote = array_column($list['items'], null, null);
        $notice = array_values(array_filter($byNote, fn (array $n): bool => $n['resource']['id'] === $this->noteIds['private_1']))[0];
        self::assertSame('share_granted', $notice['type']);
        self::assertSame(['id' => $this->userIds['owner'], 'email' => $this->email('owner')], $notice['actor']);
        self::assertSame(['type' => 'note', 'id' => $this->noteIds['private_1'], 'title' => $this->runId.' private_1', 'available' => true], $notice['resource']);
        self::assertSame(sprintf('%s shared a note with you: "%s private_1".', $this->email('owner'), $this->runId), $notice['message']);
        self::assertSame(['/account/shared', null], [$notice['link'], $notice['read_at']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $notice['created_at']);

        // Reading one: the count goes down by one; reading it again changes nothing.
        self::assertSame(200, $this->api('unrelated', 'POST', '/api/notifications/'.$notice['id'].'/read'));
        $readAt = $this->json()['read_at'];
        self::assertNotNull($readAt);
        self::assertSame($notice['id'], $this->json()['id']);
        $this->em()->getConnection()->executeStatement("UPDATE notifications SET read_at = '2026-01-01 00:00:00' WHERE id = ?", [$notice['id']]);
        self::assertSame(200, $this->api('unrelated', 'POST', '/api/notifications/'.$notice['id'].'/read'));
        self::assertSame('2026-01-01T00:00:00+00:00', $this->json()['read_at'], 'a replay keeps the first read time');
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications/unread-count'));
        self::assertSame(['unread' => 1], $this->json());

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications?unread=true'));
        self::assertSame([1, 1, [$this->noteIds['private_2']]], [$this->json()['total'], $this->json()['unread'], array_column(array_column($this->json()['items'], 'resource'), 'id')]);

        self::assertSame(200, $this->api('unrelated', 'POST', '/api/notifications/read-all'));
        self::assertSame(['updated' => 1, 'unread' => 0], $this->json());
        self::assertSame(200, $this->api('unrelated', 'POST', '/api/notifications/read-all'));
        self::assertSame(['updated' => 0, 'unread' => 0], $this->json());
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        self::assertSame([2, 0], [$this->json()['total'], $this->json()['unread']]);

        // The one who shared gets nothing.
        self::assertSame(200, $this->api('owner', 'GET', '/api/notifications/unread-count'));
        self::assertSame(['unread' => 0], $this->json());
    }

    public function testRevokedContentIsRedacted(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('unrelated'), 'role' => 'viewer']));
        $shareUri = $this->sharesUri('private_1').'/'.$this->json()['id'];
        self::assertSame(200, $this->api('owner', 'PUT', $this->noteUri('private_1'), ['title' => 'Top secret plan', 'content' => 'c']));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        self::assertStringContainsString('Top secret plan', $this->json()['items'][0]['message']);

        self::assertSame(204, $this->api('owner', 'DELETE', $shareUri));

        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Top secret plan', $body, 'nothing about the note is shown once access is gone');
        $items = array_column($this->json()['items'], null, 'type');
        self::assertEqualsCanonicalizing(['share_granted', 'share_revoked'], array_keys($items));
        foreach ($items as $type => $item) {
            self::assertSame(['type' => 'note', 'id' => $this->noteIds['private_1'], 'title' => null, 'available' => false], $item['resource'], $type);
            self::assertNull($item['link'], $type);
        }
        self::assertSame($this->email('owner').' shared a note with you.', $items['share_granted']['message']);
        self::assertSame($this->email('owner').' removed your access to a note.', $items['share_revoked']['message']);

        // Shared again: the old notification shows the title again, the revoked one never does.
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('unrelated'), 'role' => 'viewer']));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        $titles = array_column(array_map(static fn (array $n): array => ['type' => $n['type'], 'title' => $n['resource']['title']], $this->json()['items']), 'title', 'type');
        self::assertSame([null, 'Top secret plan'], [$titles['share_revoked'], $titles['share_granted']]);

        // Deleting the note leaves the notifications, without it.
        self::assertSame(204, $this->api('owner', 'DELETE', $this->noteUri('private_1')));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        self::assertSame([false, false, false], array_column(array_column($this->json()['items'], 'resource'), 'available'));
    }

    public function testTaskNotifications(): void
    {
        // Assigned to someone else: they are told. Assigned to myself: nobody is.
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'For the editor', 'assignee_id' => $this->userIds['editor']]));
        $task = $this->json();
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'Mine', 'assignee_id' => $this->userIds['owner']]));

        self::assertSame([['task_assigned', 'For the editor']], $this->notices('editor'));
        self::assertSame([], $this->notices('owner'));

        // An edit that does not change the assignee tells nobody; a new assignee is told.
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['priority' => 'high']));
        self::assertCount(1, $this->notices('editor'));
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$task['id'], ['assignee_id' => $this->userIds['viewer']]));
        self::assertSame([['task_assigned', 'For the editor']], $this->notices('viewer'));
        // The former assignee no longer sees what the task was called.
        self::assertSame([['task_assigned', null]], $this->notices('editor'));

        // Completed by the assignee: the owner is told, with a link.
        foreach (['todo', 'in_progress', 'done'] as $status) {
            self::assertSame(200, $this->api('viewer', 'POST', '/api/tasks/'.$task['id'].'/transition', ['status' => $status]));
        }
        self::assertSame([['task_completed', 'For the editor']], $this->notices('owner'));
        self::assertSame(200, $this->api('owner', 'GET', '/api/notifications'));
        self::assertSame([$this->email('viewer').' completed a task: "For the editor".', '/account/tasks', $this->userIds['viewer']], [$this->json()['items'][0]['message'], $this->json()['items'][0]['link'], $this->json()['items'][0]['actor']['id']]);

        // Completed by the owner: the assignee is told.
        self::assertSame(200, $this->api('owner', 'POST', '/api/tasks/'.$task['id'].'/transition', ['status' => 'in_progress']));
        self::assertSame(200, $this->api('owner', 'POST', '/api/tasks/'.$task['id'].'/transition', ['status' => 'done']));
        self::assertEqualsCanonicalizing(['task_assigned', 'task_completed'], array_column($this->notices('viewer'), 0));
        self::assertCount(1, $this->notices('owner'));
    }

    public function testDueTasksNotifyOnceAndOnlyTheirPerson(): void
    {
        $soon = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $later = gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 86400);
        $past = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);

        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'Due soon, unassigned', 'due_at' => $soon]));
        $own = $this->json()['id'];
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'Overdue, for the editor', 'due_at' => $past, 'assignee_id' => $this->userIds['editor']]));
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'Due next week', 'due_at' => $later]));
        self::assertSame(201, $this->api('owner', 'POST', '/api/tasks', ['title' => 'No due time']));

        self::assertSame([['task_due', 'Due soon, unassigned']], $this->notices('owner'));
        self::assertEqualsCanonicalizing([['task_assigned', 'Overdue, for the editor'], ['task_due', 'Overdue, for the editor']], $this->notices('editor'));
        self::assertSame(200, $this->api('owner', 'GET', '/api/notifications'));
        self::assertSame(['A task is due soon: "Due soon, unassigned".', null], [$this->json()['items'][0]['message'], $this->json()['items'][0]['actor']]);

        // Asking again, or running the job, does not repeat them.
        self::assertSame(200, $this->api('owner', 'GET', '/api/notifications/unread-count'));
        self::assertSame(['unread' => 1], $this->json());
        $tester = new CommandTester((new Application(self::$kernel))->find('app:notifications:run'));
        self::assertSame(0, $tester->execute([]));
        self::assertCount(1, $this->notices('owner'));

        // A new due time is a new reminder; a finished task gets none.
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$own, ['due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)]));
        self::assertCount(2, $this->notices('owner'));
        foreach (['todo', 'in_progress', 'done'] as $status) {
            $this->api('owner', 'POST', '/api/tasks/'.$own.'/transition', ['status' => $status]);
        }
        self::assertSame(200, $this->api('owner', 'PATCH', '/api/tasks/'.$own, ['due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)]));
        self::assertCount(2, $this->notices('owner'));
    }

    public function testTheSameEventIsStoredAndMailedOnce(): void
    {
        $em = $this->em();
        $notifier = self::getContainer()->get(Notifier::class);
        $recipient = $em->find(User::class, $this->userIds['viewer']);
        $actor = $em->find(User::class, $this->userIds['owner']);

        self::assertTrue($notifier->notify($recipient, Notification::SHARE_GRANTED, 'event-42', $actor, 'note', $this->noteIds['shared_1']));
        for ($i = 0; $i < 3; ++$i) {
            self::assertFalse($notifier->notify($recipient, Notification::SHARE_GRANTED, 'event-42', $actor, 'note', $this->noteIds['shared_1']));
        }
        // The same event for another user, and another event for the same user, are separate.
        self::assertTrue($notifier->notify($actor, Notification::SHARE_GRANTED, 'event-42', $actor, 'note', $this->noteIds['shared_1']));
        self::assertTrue($notifier->notify($recipient, Notification::SHARE_GRANTED, 'event-43', $actor, 'note', $this->noteIds['shared_1']));

        self::assertSame(2, $em->getRepository(Notification::class)->count(['user' => $recipient]));
        self::assertCount(2, $this->notices('viewer'));
    }

    public function testEmailOptOutIsHonoredAndOnlyCoversNotificationMail(): void
    {
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/account/notification-preferences'));
        self::assertSame(['email' => ['share_granted' => true, 'share_revoked' => true, 'task_assigned' => true, 'task_due' => true, 'task_completed' => true]], $this->json());

        // By default a share is also mailed.
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('unrelated'), 'role' => 'viewer']));
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', $this->email('unrelated'));
        self::assertEmailSubjectContains($mail, 'A note was shared with you');
        self::assertStringNotContainsString($this->runId.' private_1', $mail->getTextBody(), 'the mail does not carry the title');
        $shareUri = $this->sharesUri('private_1').'/'.$this->json()['id'];

        self::assertSame(200, $this->api('unrelated', 'PATCH', '/api/account/notification-preferences', ['email' => ['share_granted' => false]]));
        self::assertSame(['share_granted' => false, 'share_revoked' => true], array_slice($this->json()['email'], 0, 2, true));

        // Opted out of that type: still notified in the app, no mail. Other types still mail.
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_2'), ['email' => $this->email('unrelated'), 'role' => 'viewer']));
        self::assertEmailCount(0);
        self::assertCount(2, $this->notices('unrelated'));
        self::assertSame(204, $this->api('owner', 'DELETE', $shareUri));
        self::assertEmailCount(1);
        self::assertEmailSubjectContains(self::getMailerMessage(), 'Your access to a note was removed');

        // Account mail is not optional.
        self::assertSame(200, $this->api('unrelated', 'PATCH', '/api/account/notification-preferences', ['email' => array_fill_keys(Notification::TYPES, false)]));
        self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $this->email('unrelated')]));
        self::assertEmailCount(1);

        // Opting back in, and preferences are per user.
        self::assertSame(200, $this->api('unrelated', 'PATCH', '/api/account/notification-preferences', ['email' => ['share_granted' => true]]));
        self::assertSame(['share_granted' => true, 'share_revoked' => false], array_slice($this->json()['email'], 0, 2, true));
        self::assertSame(200, $this->api('owner', 'GET', '/api/account/notification-preferences'));
        self::assertNotContains(false, $this->json()['email']);

        $invalid = [[], ['email' => null], ['email' => 'none'], ['email' => [true]], ['email' => ['share_granted' => 'no']], ['email' => ['share_granted' => 0]],
            ['email' => ['share_granted' => null]], ['email' => ['unknown_type' => false]], ['email' => ['share_granted' => true], 'push' => []]];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->api('unrelated', 'PATCH', '/api/account/notification-preferences', $body), json_encode($body));
        }
        self::assertSame(400, $this->api('unrelated', 'PATCH', '/api/account/notification-preferences', '{'));
        self::assertSame(401, $this->api(null, 'GET', '/api/account/notification-preferences'));
        self::assertSame(401, $this->api(null, 'PATCH', '/api/account/notification-preferences', ['email' => []]));
    }

    public function testNotificationsAreScopedToTheirUser(): void
    {
        self::assertSame(201, $this->api('owner', 'POST', $this->sharesUri('private_1'), ['email' => $this->email('unrelated'), 'role' => 'viewer']));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications'));
        $id = $this->json()['items'][0]['id'];

        foreach (['owner', 'viewer', 'editor'] as $persona) {
            self::assertSame(404, $this->api($persona, 'POST', '/api/notifications/'.$id.'/read'), $persona);
            self::assertSame(['error' => 'Notification not found.'], $this->json());
            self::assertSame(200, $this->api($persona, 'POST', '/api/notifications/read-all'));
        }
        self::assertSame(404, $this->api('unrelated', 'POST', '/api/notifications/00000000-0000-4000-8000-000000000000/read'));
        self::assertSame(404, $this->api('unrelated', 'POST', '/api/notifications/not-an-id/read'));
        self::assertSame(200, $this->api('unrelated', 'GET', '/api/notifications/unread-count'));
        self::assertSame(['unread' => 1], $this->json(), 'nobody else could mark it read');

        foreach ([['GET', '/api/notifications'], ['GET', '/api/notifications/unread-count'], ['POST', '/api/notifications/read-all'], ['POST', '/api/notifications/'.$id.'/read']] as [$method, $uri]) {
            self::assertSame(401, $this->api(null, $method, $uri), $uri);
            self::assertSame(401, $this->api(null, $method, $uri, null, ['Authorization' => 'Bearer '.$this->token('unrelated', time() - 60)]), $uri);
        }
        foreach (['page=0', 'limit=0', 'limit=101', 'unread=yes', 'unread[]=true'] as $query) {
            self::assertSame(422, $this->api('unrelated', 'GET', '/api/notifications?'.$query), $query);
        }
    }

    public function testPaginationAndRetention(): void
    {
        $connection = $this->em()->getConnection();
        for ($i = 1; $i <= 25; ++$i) {
            $connection->insert('notifications', [
                'id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'user_id' => $this->userIds['viewer'], 'type' => 'share_granted', 'event_id' => 'seed-'.$i,
                'actor_id' => $this->userIds['owner'], 'resource_type' => 'note', 'resource_id' => $this->noteIds['shared_1'],
                // The first five are older than the retention period.
                'created_at' => gmdate('Y-m-d H:i:s', time() - ($i <= 5 ? 91 * 86400 : $i * 60)),
            ]);
        }

        self::assertSame(200, $this->api('viewer', 'GET', '/api/notifications'));
        self::assertSame([20, 20, 20], [$this->json()['total'], $this->json()['unread'], count($this->json()['items'])]);
        self::assertSame('00000000-0000-4000-8000-000000000006', $this->json()['items'][0]['id'], 'newest first');
        self::assertSame(200, $this->api('viewer', 'GET', '/api/notifications?limit=8&page=3'));
        self::assertSame(['00000000-0000-4000-8000-000000000022', '00000000-0000-4000-8000-000000000025'], [$this->json()['items'][0]['id'], $this->json()['items'][3]['id']]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND created_at < ?', [$this->userIds['viewer'], gmdate('Y-m-d H:i:s', time() - 90 * 86400)]));
    }

    public function testOpenApiDocumentsNotificationRoutes(): void
    {
        self::assertSame(200, $this->api(null, 'GET', '/api/doc.json'));
        $paths = $this->json()['paths'];

        self::assertArrayHasKey('get', $paths['/api/notifications']);
        self::assertArrayHasKey('get', $paths['/api/notifications/unread-count']);
        self::assertArrayHasKey('post', $paths['/api/notifications/read-all']);
        self::assertEqualsCanonicalizing(['200', '401', '404', '500'], array_map('strval', array_keys($paths['/api/notifications/{id}/read']['post']['responses'])));
        self::assertEqualsCanonicalizing(['200', '400', '401', '422', '500'], array_map('strval', array_keys($paths['/api/account/notification-preferences']['patch']['responses'])));
    }

    /**
     * @return list<array{string, string|null}> [type, resource title] of the persona's notifications
     */
    private function notices(string $persona): array
    {
        self::assertSame(200, $this->api($persona, 'GET', '/api/notifications?limit=100'));

        return array_map(static fn (array $n): array => [$n['type'], $n['resource']['title']], $this->json()['items']);
    }
}
