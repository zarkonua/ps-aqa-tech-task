<?php

declare(strict_types=1);

namespace App\Tests\Application\TestAdmin;

use App\Entity\TestRun;
use App\Service\BugCatalog;
use App\Service\RunManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BugInjectionTest extends WebTestCase
{
    private const ADMIN = 'test-admin-token-for-phpunit';
    private const PASSWORD = 'Fixture-Passw0rd!';
    private const ALL = ['BUG-001', 'BUG-002', 'BUG-003', 'BUG-004', 'BUG-005', 'BUG-006', 'BUG-007', 'BUG-008', 'BUG-009', 'BUG-010'];

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
        $em->clear();
        $manager = $this->client->getContainer()->get(RunManager::class);
        foreach ($em->getRepository(TestRun::class)->findBy(['id' => $this->runIds]) as $run) {
            $manager->delete($run);
        }
        $em->getConnection()->executeStatement("DELETE FROM audit_events WHERE event_type LIKE 'run.%'");
        $em->getConnection()->executeStatement('DELETE FROM throttle_hits');

        parent::tearDown();
    }

    public function testCatalogDescribesEveryDefect(): void
    {
        self::assertSame(200, $this->call('GET', '/test-admin/bugs'));
        $bugs = $this->json()['bugs'];

        self::assertSame(self::ALL, array_column($bugs, 'bug_id'));
        foreach ($bugs as $bug) {
            self::assertSame(['bug_id', 'challenge_id', 'name', 'severity', 'feature', 'surface', 'preconditions', 'expected', 'actual', 'remediation', 'conflicts'], array_keys($bug));
            self::assertMatchesRegularExpression('/^ch-[0-9a-f]{6}$/', $bug['challenge_id']);
            self::assertContains($bug['severity'], ['minor', 'major', 'critical']);
            // The challenge ID says nothing about the defect.
            self::assertStringNotContainsStringIgnoringCase(substr($bug['bug_id'], 4), $bug['challenge_id']);
        }
        self::assertCount(10, array_unique(array_column($bugs, 'challenge_id')));
        self::assertEqualsCanonicalizing(['api', 'auth', 'filter', 'state', 'sort', 'async', 'ui'], array_values(array_unique(array_column($bugs, 'surface'))));
    }

    public function testBaselineHasNoDefects(): void
    {
        $run = $this->createRun();

        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$run['run_id'].'/bugs'));
        self::assertSame(['run_id' => $run['run_id'], 'active_bugs' => []], $this->json());

        $oracle = $this->oracle($run);
        self::assertTrue($oracle['consistent']);
        self::assertSame(self::ALL, array_column($oracle['results'], 'bug_id'));
        foreach ($oracle['results'] as $result) {
            self::assertSame(['bug_id', 'challenge_id', 'active', 'expected', 'actual', 'manifested', 'consistent', 'error'], array_keys($result));
            self::assertSame([false, false, true, null], [$result['active'], $result['manifested'], $result['consistent'], $result['error']], $result['bug_id']);
            self::assertSame($result['expected'], $result['actual'], $result['bug_id']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bugIds(): iterable
    {
        foreach (self::ALL as $bugId) {
            yield $bugId => [$bugId];
        }
    }

    #[DataProvider('bugIds')]
    public function testEachDefectShowsOnlyWhenSwitchedOn(string $bugId): void
    {
        $run = $this->createRun();
        $other = $this->createRun();
        $challenge = BugCatalog::get($bugId)['challenge_id'];

        // Switched on by its opaque challenge ID.
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/bugs', ['bug_ids' => [$challenge]]));
        self::assertSame(['run_id' => $run['run_id'], 'active_bugs' => [['bug_id' => $bugId, 'challenge_id' => $challenge]]], $this->json());

        $oracle = $this->oracle($run);
        self::assertTrue($oracle['consistent'], json_encode($oracle['results']));
        foreach ($oracle['results'] as $result) {
            self::assertSame($result['bug_id'] === $bugId, $result['manifested'], $result['bug_id'].': '.json_encode([$result['expected'], $result['actual']]));
            self::assertSame($result['bug_id'] === $bugId, $result['active']);
        }
        $shown = array_column($oracle['results'], null, 'bug_id')[$bugId];
        self::assertNotSame($shown['expected'], $shown['actual']);

        // Another run at the same time is not affected.
        $clean = $this->oracle($other);
        self::assertSame([], array_filter(array_column($clean['results'], 'manifested')), 'the other run stays correct');

        // Switched off: correct again.
        self::assertSame(204, $this->call('DELETE', '/test-admin/runs/'.$run['run_id'].'/bugs'));
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/reset'));
        $after = $this->oracle($run);
        self::assertTrue($after['consistent']);
        self::assertSame([], array_filter(array_column($after['results'], 'manifested')));
    }

    public function testADefectIsVisibleToATesterOfThatRun(): void
    {
        $run = $this->createRun();
        $viewer = $this->signIn($run['manifest']['users']['viewer']['email']);
        $owner = $this->signIn($run['manifest']['users']['owner']['email']);
        $shared = '/api/notes/'.$run['manifest']['notes']['shared_1']['id'];

        self::assertSame(403, $this->api($viewer, 'PUT', $shared, ['title' => 'By the viewer', 'content' => 'x']));
        self::assertSame(201, $this->api($owner, 'POST', '/api/tasks', ['title' => 'Before']));

        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/bugs', ['bug_ids' => ['BUG-002', 'BUG-006', 'BUG-009']]));
        self::assertSame(['BUG-002', 'BUG-006', 'BUG-009'], array_column($this->json()['active_bugs'], 'bug_id'));

        self::assertSame(200, $this->api($viewer, 'PUT', $shared, ['title' => 'By the viewer', 'content' => 'x']));
        self::assertSame(200, $this->api($owner, 'POST', '/api/tasks', ['title' => 'After']));
        self::assertSame(200, $this->api($owner, 'GET', '/api/notes'));
        self::assertSame([4, '5'], [count($this->json()), $this->client->getResponse()->headers->get('X-Total-Count')]);

        // A reset restores the clean baseline: data and switches.
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/reset'));
        self::assertSame(0, $this->json()['active_bug_count']);
        $viewer = $this->signIn($run['manifest']['users']['viewer']['email']);
        self::assertSame(403, $this->api($viewer, 'PUT', $shared, ['title' => 'By the viewer', 'content' => 'x']));
        self::assertSame(201, $this->api($this->signIn($run['manifest']['users']['owner']['email']), 'POST', '/api/tasks', ['title' => 'Clean']));
    }

    public function testNothingTellsAnAgentWhatIsSwitchedOn(): void
    {
        $run = $this->createRun();
        self::assertSame(200, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/bugs', ['bug_ids' => ['BUG-003', 'BUG-007']]));

        // Every persona, including the run's application administrator, is refused the catalog and the state.
        foreach (['owner', 'viewer', 'admin'] as $persona) {
            $jwt = $this->signIn($run['manifest']['users'][$persona]['email']);
            foreach (['/test-admin/bugs', '/test-admin/runs/'.$run['run_id'].'/bugs', '/test-admin/runs/'.$run['run_id'].'/oracle-results', '/test-admin/runs/'.$run['run_id']] as $path) {
                self::assertSame(401, $this->call('GET', $path, null, $jwt), "$persona $path");
            }
            self::assertSame(401, $this->call('POST', '/test-admin/runs/'.$run['run_id'].'/bugs', ['bug_ids' => ['BUG-001']], $jwt));
            self::assertSame(401, $this->call('DELETE', '/test-admin/runs/'.$run['run_id'].'/bugs', null, $jwt));

            // Nothing in what the application itself serves names a defect.
            foreach (['/api/notes', '/api/auth/me', '/api/account/profile', '/api/account/settings', '/api/tasks', '/api/notifications', '/api/tags', '/api/doc.json', '/'] as $path) {
                $this->api($jwt, 'GET', $path);
                $response = $this->client->getResponse();
                $served = $response->headers->__toString().$response->getContent();
                foreach (['BUG-0', 'ch-c31d08', 'ch-e1930a', 'active_bugs', 'challenge', 'oracle', 'inject'] as $needle) {
                    self::assertStringNotContainsStringIgnoringCase($needle, $served, "$persona $path");
                }
            }
        }
        $admin = $this->signIn($run['manifest']['users']['admin']['email']);
        self::assertSame(200, $this->api($admin, 'GET', '/api/audit-events?limit=100'));
        self::assertStringNotContainsString('BUG-', (string) $this->client->getResponse()->getContent());

        // The catalog is for the administrator token only; a run token manages its own run's switches.
        self::assertSame(403, $this->call('GET', '/test-admin/bugs', null, $run['run_token']));
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$run['run_id'].'/bugs', null, $run['run_token']));
        $other = $this->createRun();
        self::assertSame(403, $this->call('POST', '/test-admin/runs/'.$other['run_id'].'/bugs', ['bug_ids' => ['BUG-001']], $run['run_token']));
        self::assertSame(403, $this->call('GET', '/test-admin/runs/'.$other['run_id'].'/oracle-results', null, $run['run_token']));
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$other['run_id'].'/bugs'));
        self::assertSame([], $this->json()['active_bugs']);
    }

    public function testInvalidAndConflictingSelectionsAreRefused(): void
    {
        $run = $this->createRun();
        $uri = '/test-admin/runs/'.$run['run_id'].'/bugs';
        self::assertSame(200, $this->call('POST', $uri, ['bug_ids' => ['BUG-004']]));

        $invalid = [[], ['bug_ids' => []], ['bug_ids' => null], ['bug_ids' => 'BUG-001'], ['bug_ids' => ['BUG-999']], ['bug_ids' => ['bug-001']], ['bug_ids' => [1]],
            ['bug_ids' => ['BUG-001', null]], ['bug_ids' => ['a' => 'BUG-001']], ['bug_ids' => ['BUG-001'], 'severity' => 'x'], ['bugIds' => ['BUG-001']]];
        foreach ($invalid as $body) {
            self::assertSame(422, $this->call('POST', $uri, $body), json_encode($body));
        }
        // Defects that would hide each other.
        foreach ([['BUG-001', 'BUG-005'], ['BUG-001', 'BUG-009'], ['ch-4f7a1c', 'BUG-009', 'BUG-002']] as $pair) {
            self::assertSame(422, $this->call('POST', $uri, ['bug_ids' => $pair]), implode(',', $pair));
            self::assertStringContainsString('cannot be switched on together', $this->json()['violations'][0]['message']);
        }
        self::assertSame(400, $this->call('POST', $uri, '{"bug_ids": '));
        self::assertSame(404, $this->call('POST', '/test-admin/runs/no-such-run/bugs', ['bug_ids' => ['BUG-001']]));
        self::assertSame(404, $this->call('GET', '/test-admin/runs/no-such-run/oracle-results'));

        // None of that changed the selection. The same ID twice, or as bug ID and challenge ID, counts once.
        self::assertSame(200, $this->call('GET', $uri));
        self::assertSame(['BUG-004'], array_column($this->json()['active_bugs'], 'bug_id'));
        self::assertSame(200, $this->call('POST', $uri, ['bug_ids' => ['BUG-010', 'ch-06a2e5', 'BUG-002', 'BUG-010']]));
        self::assertSame(['BUG-002', 'BUG-010'], array_column($this->json()['active_bugs'], 'bug_id'), 'a new selection replaces the old one');
        // All that can be combined at once.
        self::assertSame(200, $this->call('POST', $uri, ['bug_ids' => array_values(array_diff(self::ALL, ['BUG-001']))]));
        self::assertTrue($this->oracle($run)['consistent']);
        self::assertSame(204, $this->call('DELETE', $uri));
        self::assertSame(204, $this->call('DELETE', $uri));
    }

    /**
     * @return array<string, mixed>
     */
    private function oracle(array $run): array
    {
        self::assertSame(200, $this->call('GET', '/test-admin/runs/'.$run['run_id'].'/oracle-results'), (string) $this->client->getResponse()->getContent());
        self::assertSame(['run_id', 'examined_at', 'consistent', 'results'], array_keys($this->json()));

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function createRun(): array
    {
        self::assertSame(201, $this->call('POST', '/test-admin/runs', ['fixture' => 'basic-notes']));
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
    private function api(string $jwt, string $method, string $path, ?array $body = null): int
    {
        return $this->call($method, $path, $body, $jwt);
    }

    private function signIn(string $email): string
    {
        self::assertSame(200, $this->call('POST', '/api/auth/signin', ['email' => $email, 'password' => self::PASSWORD], null));

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
