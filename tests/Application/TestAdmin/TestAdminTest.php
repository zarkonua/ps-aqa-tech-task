<?php

declare(strict_types=1);

namespace App\Tests\Application\TestAdmin;

use App\Entity\AuditEvent;
use App\Entity\TestRun;
use App\Entity\User;
use App\Kernel;
use App\Service\AttachmentStorage;
use App\Service\RunManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class TestAdminTest extends WebTestCase
{
    private const ADMIN = 'test-admin-token-for-phpunit';
    private const PASSWORD = 'Fixture-Passw0rd!';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $runIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $manager = $this->client->getContainer()->get(RunManager::class);
        $em->clear();
        foreach ($em->getRepository(TestRun::class)->findAll() as $run) {
            if (in_array($run->getId(), $this->runIds, true) || str_starts_with($run->getId(), 'quota-')) {
                $manager->delete($run);
            }
        }
        $em->createQueryBuilder()->delete(AuditEvent::class, 'a')->where('a.eventType LIKE :type')->setParameter('type', 'run.%')->getQuery()->execute();
        $em->getConnection()->executeStatement('DELETE FROM throttle_hits');

        parent::tearDown();
    }

    public function testOnlyTestAdminTokensAreAccepted(): void
    {
        $run = $this->createRun('basic-notes');
        $jwt = $this->signIn($run['manifest']['users']['admin']['email']);

        foreach ([['GET', '/test-admin/runs'], ['POST', '/test-admin/runs'], ['GET', '/test-admin/fixtures'], ['GET', '/test-admin/openapi.json'], ['GET', '/test-admin/runs/'.$run['run_id']], ['POST', '/test-admin/runs/'.$run['run_id'].'/reset'], ['DELETE', '/test-admin/runs/'.$run['run_id']]] as [$method, $path]) {
            self::assertSame(401, $this->call($method, $path, null, null), "$method $path without a token");
            self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
            self::assertSame(['error' => 'A test-admin token is required.'], $this->json());
            foreach (['wrong-token', self::ADMIN.'x', strtoupper(self::ADMIN), $jwt] as $token) {
                // An application sign-in, even an application administrator's, is not a test-admin token.
                self::assertSame(401, $this->call($method, $path, null, $token), "$method $path");
                self::assertSame(['error' => 'Invalid test-admin token.'], $this->json());
            }
        }
        // The token is not read from the URL or the body.
        self::assertSame(401, $this->call('GET', '/test-admin/runs?token='.self::ADMIN, null, null));
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$run['run_id']), 'the run survived all of that');
    }

    public function testCreateRunAndSignInAsItsPersonas(): void
    {
        self::assertSame(201, $this->call('POST', '/test-admin/runs', ['fixture' => 'sharing-rbac', 'seed' => 7]));
        $run = $this->json();
        $this->runIds[] = $run['run_id'];

        self::assertSame(
            ['run_id', 'fixture', 'fixture_version', 'seed', 'manifest_hash', 'resets', 'clock_offset_seconds', 'clock_now', 'created_at', 'updated_at', 'run_token', 'manifest'],
            array_keys($run),
        );
        self::assertMatchesRegularExpression('/^r[0-9a-f]{12}$/', $run['run_id']);
        self::assertSame(['sharing-rbac', 7, 0, 0], [$run['fixture'], $run['seed'], $run['resets'], $run['clock_offset_seconds']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['manifest_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $run['run_token']);
        self::assertSame('/test-admin/runs/'.$run['run_id'], $this->client->getResponse()->headers->get('Location'));

        $manifest = $run['manifest'];
        self::assertSame(['sharing-rbac', $run['run_id'], 7, ['history', 'notifications', 'audit']], [$manifest['fixture'], $manifest['run_id'], $manifest['seed'], $manifest['parts']]);
        self::assertSame(['owner', 'viewer', 'editor', 'unrelated', 'unverified', 'admin'], array_keys($manifest['users']));
        self::assertSame('owner.'.$run['run_id'].'@qa-fixtures.test', $manifest['users']['owner']['email']);
        self::assertSame([[], [], []], [$manifest['search_notes'], $manifest['bulk_notes'], $manifest['tasks']], 'parts that are not in this fixture are empty');
        self::assertCount(8, $manifest['audit_events']);

        // The personas exist and work.
        $jwt = $this->signIn($manifest['users']['viewer']['email']);
        self::assertSame(200, $this->api($jwt, 'GET', '/api/notes?scope=shared'));
        self::assertCount(2, $this->json());
        self::assertSame(401, $this->signInStatus($manifest['users']['unverified']['email']));

        // The run token is not stored or shown again.
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$run['run_id'].'/manifest'));
        self::assertArrayNotHasKey('run_token', $this->json());
        self::assertSame($manifest, $this->json()['manifest']);
        self::assertStringNotContainsString($run['run_token'], json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM test_runs')));
        self::assertSame(200, $this->call('GET', '/test-admin/runs'));
        self::assertContains($run['run_id'], array_column($this->json()['items'], 'run_id'));
        self::assertSame(50, $this->json()['max']);

        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $run['run_id'], 'eventType' => 'run.created']));
    }

    public function testTheSameSeedGivesTheSameContentInDisjointRuns(): void
    {
        $a = $this->createRun('search-edge', 5);
        $b = $this->createRun('search-edge', 5);
        $other = $this->createRun('search-edge', 6);
        $otherFixture = $this->createRun('bulk-mixed', 5);

        self::assertSame($a['manifest_hash'], $b['manifest_hash'], 'same fixture and seed: same content');
        self::assertNotSame($a['manifest_hash'], $other['manifest_hash'], 'another seed: other content');
        self::assertNotSame($a['manifest_hash'], $otherFixture['manifest_hash']);

        // Same content, title for title…
        $titles = static fn (array $run): array => array_map(static fn (array $n): string => str_replace($run['run_id'], '', $n['title']), $run['manifest']['search_notes']);
        self::assertSame($titles($a), $titles($b));
        self::assertNotSame($titles($a), $titles($other));
        // …but nothing shared: every identifier and address differs.
        $ids = static fn (array $run): array => [...array_column($run['manifest']['users'], 'id'), ...array_column($run['manifest']['users'], 'email'), ...array_column($run['manifest']['notes'], 'id'), ...array_column($run['manifest']['search_notes'], 'id')];
        self::assertSame([], array_intersect($ids($a), $ids($b)));

        // Neither run can see into the other.
        $ownerA = $this->signIn($a['manifest']['users']['owner']['email']);
        $ownerB = $this->signIn($b['manifest']['users']['owner']['email']);
        self::assertSame(404, $this->api($ownerA, 'GET', '/api/notes/'.$b['manifest']['notes']['private_1']['id']));
        self::assertSame(404, $this->api($ownerA, 'POST', '/api/notes/'.$b['manifest']['notes']['private_1']['id'].'/shares', ['email' => $a['manifest']['users']['viewer']['email'], 'role' => 'viewer']));
        self::assertSame(200, $this->api($ownerA, 'GET', '/api/notes?scope=all&limit=100'));
        self::assertSame([], array_intersect(array_column($this->json(), 'id'), $ids($b)));
        // 4 base notes, 40 own search notes and 10 of the editor's that are shared with the owner.
        self::assertSame(54, (int) $this->client->getResponse()->headers->get('X-Total-Count'));
        self::assertSame(200, $this->api($ownerB, 'GET', '/api/notes?scope=all&limit=1'));
        self::assertSame(54, (int) $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testResetClearsEverySideEffect(): void
    {
        $run = $this->createRun('attachments');
        $untouched = $this->createRun('basic-notes');
        $manifest = $run['manifest'];
        $ownerEmail = $manifest['users']['owner']['email'];
        $jwt = $this->signIn($ownerEmail);
        $shared = '/api/notes/'.$manifest['notes']['shared_1']['id'];
        $storage = self::getContainer()->get(AttachmentStorage::class);
        $filesBefore = count($storage->keys());

        // Tests leave their marks everywhere.
        self::assertSame(201, $this->api($jwt, 'POST', '/api/notes', ['title' => 'Left behind', 'content' => 'x']));
        self::assertSame(204, $this->api($jwt, 'DELETE', '/api/notes/'.$manifest['notes']['private_1']['id']));
        self::assertSame(200, $this->api($jwt, 'PUT', $shared, ['title' => 'Changed', 'content' => 'changed']));
        self::assertSame(204, $this->api($jwt, 'DELETE', $shared.'/shares/'.$manifest['notes']['shared_1']['shares']['viewer']['id']));
        self::assertSame(204, $this->api($jwt, 'DELETE', '/api/attachments/'.$manifest['notes']['shared_1']['attachments']['pdf']['id']));
        $this->client->request('POST', $shared.'/attachments', [], ['file' => new UploadedFile(__DIR__.'/../../fixtures/attachments/notes.txt', 'extra.txt', null, null, true)], ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(201, $this->api($jwt, 'POST', '/api/tags', ['name' => 'Leftover']));
        self::assertSame(201, $this->api($jwt, 'POST', '/api/tasks', ['title' => 'Leftover task']));
        self::assertSame(200, $this->api($jwt, 'PATCH', '/api/account/settings', ['timezone' => 'Asia/Tokyo']));
        self::assertSame(200, $this->api($jwt, 'GET', '/api/realtime/notes/'.$manifest['notes']['shared_2']['id'].'/poll'));
        self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $ownerEmail]));
        self::assertSame(200, $this->api($jwt, 'POST', '/api/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'a-completely-new-one']));
        $jwt = $this->json()['token'];
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/advance-clock', ['seconds' => 500]));

        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/reset'));
        $reset = $this->json();
        self::assertSame([1, 0, $run['manifest_hash'], null], [$reset['resets'], $reset['clock_offset_seconds'], $reset['manifest_hash'], $reset['mail_deleted']]);
        self::assertSame($manifest, $reset['manifest'], 'the same identifiers as before');

        // Sign-ins from before the reset are over; the fixture password works again.
        self::assertSame(401, $this->api($jwt, 'GET', '/api/notes'));
        $jwt = $this->signIn($ownerEmail);
        self::assertSame(200, $this->api($jwt, 'GET', '/api/notes?limit=100'));
        self::assertEqualsCanonicalizing(array_column($manifest['notes'], 'id'), array_column($this->json(), 'id'));
        self::assertSame(200, $this->api($jwt, 'GET', $shared));
        self::assertSame([$manifest['notes']['shared_1']['title'], 1], [$this->json()['title'], $this->json()['version']]);
        self::assertSame(200, $this->api($jwt, 'GET', $shared.'/shares'));
        self::assertSame(2, $this->json()['total']);
        self::assertSame(200, $this->api($jwt, 'GET', $shared.'/attachments'));
        self::assertEqualsCanonicalizing(['pixel.png', 'report.pdf', 'нотатки 日本語.txt'], array_column($this->json()['items'], 'filename'));
        self::assertCount($filesBefore, $storage->keys(), 'no file left over, none missing');
        self::assertSame(200, $this->api($jwt, 'GET', '/api/tags'));
        self::assertNotContains('Leftover', array_column($this->json()['items'], 'name'));
        self::assertSame(200, $this->api($jwt, 'GET', '/api/tasks'));
        self::assertSame(0, $this->json()['total']);
        self::assertSame(200, $this->api($jwt, 'GET', '/api/account/settings'));
        self::assertSame(['timezone' => 'UTC'], $this->json());

        $connection = $this->em()->getConnection();
        $userIds = array_column($manifest['users'], 'id');
        foreach (['audit_events' => 'actor_id', 'account_tokens' => 'user_id', 'note_presence' => 'user_id', 'notifications' => 'user_id'] as $table => $column) {
            $leftovers = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s IN (?)', $table, $column), [$userIds], [\Doctrine\DBAL\ArrayParameterType::STRING]);
            // The fixture's own two refused reset tokens are part of the initial state.
            self::assertSame('account_tokens' === $table ? 2 : 0, $leftovers, $table);
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM realtime_events WHERE note_id IN (?)', [array_column($manifest['notes'], 'id')], [\Doctrine\DBAL\ArrayParameterType::STRING]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM throttle_hits WHERE key_hash = ?', [hash('sha256', $ownerEmail)]));

        // Resetting again changes nothing more, and the other run was never touched.
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/reset'));
        self::assertSame([2, $manifest], [$this->json()['resets'], $this->json()['manifest']]);
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$untouched['run_id']));
        self::assertSame(0, $this->json()['resets']);
        self::assertSame(200, $this->api($this->signIn($untouched['manifest']['users']['owner']['email']), 'GET', '/api/notes'));
        self::assertCount(4, $this->json());
        self::assertSame(2, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $run['run_id'], 'eventType' => 'run.reset']));
    }

    public function testARunTokenOnlyReachesItsOwnRun(): void
    {
        $a = $this->createRun('basic-notes');
        $b = $this->createRun('basic-notes');

        // Its own run: everything but the administrator's routes.
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$a['run_id'], null, $a['run_token']));
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$a['run_id'].'/manifest', null, $a['run_token']));
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', ['seconds' => 60], $a['run_token']));
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/reset', null, $a['run_token']));

        // Another run, or the administrator's routes: known token, not allowed.
        foreach ([['GET', '/test-admin/runs/'.$b['run_id']], ['GET', '/test-admin/runs/'.$b['run_id'].'/manifest'], ['POST', '/test-admin/runs/'.$b['run_id'].'/reset'], ['POST', '/test-admin/runs/'.$b['run_id'].'/advance-clock'], ['DELETE', '/test-admin/runs/'.$b['run_id']], ['GET', '/test-admin/runs'], ['POST', '/test-admin/runs'], ['GET', '/test-admin/fixtures'], ['GET', '/test-admin/openapi.json'], ['GET', '/test-admin/runs/no-such-run']] as [$method, $path]) {
            self::assertSame(403, $this->call($method, $path, ['seconds' => 60], $a['run_token']), "$method $path");
            self::assertSame(['error' => 'This token only gives access to its own run.'], $this->json());
        }
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$b['run_id']));
        self::assertSame([0, 0], [$this->json()['resets'], $this->json()['clock_offset_seconds']], 'run B was not touched');

        // A run can remove itself; afterwards its token is nothing.
        self::assertSame(204, $this->call('DELETE', '/test-admin/runs/'.$a['run_id'], null, $a['run_token']));
        self::assertSame(401, $this->call('GET', '/test-admin/runs/'.$a['run_id'], null, $a['run_token']));
        self::assertSame(404, $this->call('GET', '/test-admin/runs/'.$a['run_id']));
        self::assertSame(['error' => 'Run not found.'], $this->json());
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $a['manifest']['users']['owner']['email']]));
        self::assertSame(1, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $a['run_id'], 'eventType' => 'run.deleted']));
    }

    public function testClockIsAdvancedForOneRunOnly(): void
    {
        $a = $this->createRun('basic-notes');
        $b = $this->createRun('basic-notes');
        $tokens = [];
        foreach ([$a, $b] as $run) {
            self::assertSame(202, $this->api(null, 'POST', '/api/auth/password-reset/request', ['email' => $run['manifest']['users']['owner']['email']]));
            preg_match('/reset_token=([a-f0-9]{64})/', self::getMailerMessage()->getTextBody(), $m);
            $tokens[$run['run_id']] = $m[1];
        }
        $jwtA = $this->signIn($a['manifest']['users']['owner']['email']);
        self::assertSame(201, $this->api($jwtA, 'POST', '/api/tasks', ['title' => 'Due in three days', 'due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 86400)]));
        self::assertSame(200, $this->api($jwtA, 'GET', '/api/notifications/unread-count'));
        self::assertSame(['unread' => 0], $this->json());

        // 29 minutes on: the 30-minute link still works (a weak password does not use it up).
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', ['seconds' => 29 * 60]));
        self::assertSame(29 * 60, $this->json()['clock_offset_seconds']);
        self::assertEqualsWithDelta(time() + 29 * 60, strtotime($this->json()['clock_now']), 5);
        self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $tokens[$a['run_id']], 'password' => 'short']));
        self::assertSame('password', $this->json()['violations'][0]['propertyPath']);

        // Two more minutes: expired in run A, and only there.
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', ['seconds' => 120]));
        self::assertSame(422, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $tokens[$a['run_id']], 'password' => 'a-long-enough-password']));
        self::assertSame('This reset link has expired. Request a new one.', $this->json()['violations'][0]['message']);
        self::assertSame(200, $this->api(null, 'POST', '/api/auth/password-reset/confirm', ['token' => $tokens[$b['run_id']], 'password' => 'a-long-enough-password']));

        // Three days on, the task is due.
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', ['seconds' => 3 * 86400]));
        self::assertSame(200, $this->api($jwtA, 'GET', '/api/notifications'));
        self::assertSame(['task_due'], array_column($this->json()['items'], 'type'));

        foreach ([[], ['seconds' => 0], ['seconds' => -60], ['seconds' => '60'], ['seconds' => 1.5], ['seconds' => null], ['seconds' => 315360001], ['seconds' => 60, 'run' => 'x']] as $body) {
            self::assertSame(422, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', $body), json_encode($body));
        }
        self::assertSame(400, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', '{'));
        // Ten years is the furthest a clock goes.
        self::assertSame(409, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/advance-clock', ['seconds' => 315360000]));
        self::assertStringContainsString('ten years', $this->json()['error']);

        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$a['run_id'].'/reset'));
        self::assertSame(0, $this->json()['clock_offset_seconds']);
        self::assertSame(3, $this->em()->getRepository(AuditEvent::class)->count(['subjectId' => $a['run_id'], 'eventType' => 'run.clock_advanced']));
    }

    public function testCreateValidationConflictsAndQuota(): void
    {
        $invalid = [['fixture' => 'everything'], ['fixture' => 5], ['fixture' => null, 'seed' => 0], ['seed' => 0], ['seed' => 1000001], ['seed' => '3'], ['seed' => 1.5],
            ['run_id' => 'Has Spaces'], ['run_id' => '-x'], ['run_id' => str_repeat('a', 33)], ['run_id' => 12], ['run_id' => "x'; --"], ['fixture' => 'full', 'password' => 'x']];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->call('POST', '/test-admin/runs', $body), json_encode($body));
            self::assertNotEmpty($this->json()['violations']);
        }
        self::assertSame(400, $this->call('POST', '/test-admin/runs', '{"fixture": '));
        self::assertSame(400, $this->call('POST', '/test-admin/runs', '["full"]'));

        $run = $this->createRun('basic-notes', 1, 'phpunit-named-run');
        self::assertSame('phpunit-named-run', $run['run_id']);
        self::assertSame(409, $this->call('POST', '/test-admin/runs', ['fixture' => 'basic-notes', 'run_id' => 'phpunit-named-run']));
        self::assertSame(['error' => 'Run "phpunit-named-run" already exists. Reset it, or choose another ID.'], $this->json());
        foreach (['No Such', 'no-such-run', '..', str_repeat('a', 40)] as $id) {
            self::assertSame(404, $this->call('GET', '/test-admin/runs/'.rawurlencode($id)), $id);
            self::assertSame(404, $this->call('POST', '/test-admin/runs/'.rawurlencode($id).'/reset'), $id);
        }

        // The quota counts all runs.
        $em = $this->em();
        $existing = $em->getRepository(TestRun::class)->count([]);
        for ($i = $existing; $i < 50; ++$i) {
            $em->persist(new TestRun('quota-'.$i, 'basic-notes', 1, 1, hash('sha256', 'quota-'.$i)));
        }
        $em->flush();
        self::assertSame(429, $this->call('POST', '/test-admin/runs', ['fixture' => 'basic-notes']));
        self::assertSame(['error' => 'The limit of 50 runs is reached. Delete runs that are no longer needed.'], $this->json());
    }

    public function testASecondChangeOfTheSameRunWaitsThenGivesUp(): void
    {
        $run = $this->createRun('basic-notes');

        // Somebody else holds the run's lock.
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());
        self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', ['test-run:'.$run['run_id']]));
        try {
            self::assertSame(409, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/reset'));
            self::assertSame(['error' => 'Run "'.$run['run_id'].'" is being changed by another request. Try again in a moment.'], $this->json());
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', ['test-run:'.$run['run_id']]);
            $other->close();
        }

        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$run['run_id']));
        self::assertSame(0, $this->json()['resets'], 'the refused reset did nothing');
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/reset'));
    }

    public function testTheApiIsNotPartOfTheApplication(): void
    {
        // Not in the public documentation…
        $this->client->request('GET', '/api/doc.json');
        $doc = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('test-admin', $doc);
        self::assertStringNotContainsString('TestRun', $doc);

        // …documented for the runner…
        self::assertSame(200, $this->call('GET', '/test-admin/openapi.json'));
        $paths = array_keys($this->json()['paths']);
        foreach (['/test-admin/runs', '/test-admin/runs/{id}', '/test-admin/runs/{id}/manifest', '/test-admin/runs/{id}/reset', '/test-admin/runs/{id}/advance-clock'] as $path) {
            self::assertContains($path, $paths);
        }
        self::assertSame(200, $this->call('GET', '/test-admin/fixtures'));
        self::assertSame(['full', 'basic-notes', 'sharing-rbac', 'search-edge', 'bulk-mixed', 'attachments', 'concurrency', 'async-faults'], array_column($this->json()['fixtures'], 'name'));

        // …and the control page asks for the token before showing anything.
        $this->client->request('GET', '/test-admin');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Test-admin token', $page);
        self::assertStringNotContainsString(self::ADMIN, $page);
    }

    public function testItDoesNotExistWithoutATokenOrInProduction(): void
    {
        $paths = [['GET', '/test-admin'], ['GET', '/test-admin/runs'], ['POST', '/test-admin/runs'], ['GET', '/test-admin/fixtures'], ['GET', '/test-admin/openapi.json'], ['POST', '/test-admin/runs/x/reset'], ['DELETE', '/test-admin/runs/x']];

        // No token configured.
        $configured = $_SERVER['TEST_ADMIN_TOKEN'];
        try {
            $_SERVER['TEST_ADMIN_TOKEN'] = $_ENV['TEST_ADMIN_TOKEN'] = '';
            self::ensureKernelShutdown();
            $client = self::createClient();
            foreach ($paths as [$method, $path]) {
                $client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ', 'HTTP_ACCEPT' => 'application/json']);
                self::assertSame(404, $client->getResponse()->getStatusCode(), "$method $path without a configured token");
            }
        } finally {
            $_SERVER['TEST_ADMIN_TOKEN'] = $_ENV['TEST_ADMIN_TOKEN'] = $configured;
        }

        // Production, even with a token configured and presented.
        self::ensureKernelShutdown();
        $production = new Kernel('prod', false);
        try {
            foreach ($paths as [$method, $path]) {
                $response = $production->handle(Request::create($path, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::ADMIN, 'HTTP_ACCEPT' => 'application/json']));
                self::assertSame(404, $response->getStatusCode(), "$method $path in production");
            }
        } finally {
            $production->shutdown();
            restore_exception_handler();
        }
        $this->client = self::createClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function createRun(string $fixture, int $seed = 1, ?string $runId = null): array
    {
        self::assertSame(201, $this->call('POST', '/test-admin/runs', ['fixture' => $fixture, 'seed' => $seed] + (null === $runId ? [] : ['run_id' => $runId])), (string) $this->client->getResponse()->getContent());
        $this->runIds[] = $this->json()['run_id'];

        return $this->json();
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    private function call(string $method, string $path, array|string|null $body = null, ?string $token = self::ADMIN): int
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'] + (null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->client->request($method, $path, [], [], $server, is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
        $this->em()->clear();

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function api(?string $jwt, string $method, string $path, ?array $body = null): int
    {
        return $this->call($method, $path, $body, $jwt);
    }

    private function signInStatus(string $email, string $password = self::PASSWORD): int
    {
        return $this->call('POST', '/api/auth/signin', ['email' => $email, 'password' => $password], null);
    }

    private function signIn(string $email, string $password = self::PASSWORD): string
    {
        self::assertSame(200, $this->signInStatus($email, $password));

        return $this->json()['token'];
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        return $this->client->getContainer()->get('doctrine')->getManager();
    }
}
