<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TestRun;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Checks, for every catalogued defect, how a test run really behaves: it sends the requests a careful
 * tester would send, as the run's own personas and through the whole application, and compares what
 * comes back with what a correct application answers.
 *
 * The probes leave traces in the run (an edited note, notifications); reset the run afterwards.
 */
final class BugOracle
{
    /** @var array<string, User> */
    private array $personas = [];

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly TokenStorageInterface $tokens,
        private readonly RequestStack $requestStack,
        private readonly BugSwitch $bugs,
        private readonly RunClock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>> one result per catalogued defect
     */
    public function examine(TestRun $run): array
    {
        $manifest = $run->getManifest();
        $active = $run->getActiveBugs();
        $outerToken = $this->tokens->getToken();
        $this->personas = [];
        foreach (['owner', 'viewer', 'unrelated'] as $persona) {
            $this->personas[$persona] = $this->entityManager->find(User::class, $manifest['users'][$persona]['id'])
                ?? throw new RunConflict('The personas of this run are missing. Reset the run and try again.');
        }

        // The probes must look like requests from outside: while they run, the runner's own request is
        // not the "main request" that the application's code would otherwise find.
        $outerRequest = $this->requestStack->pop();
        $results = [];
        try {
            foreach (BugCatalog::all() as $bug) {
                try {
                    [$expected, $actual] = $this->probe($bug['bug_id'], $manifest);
                    $error = null;
                } catch (\Throwable $e) {
                    [$expected, $actual, $error] = [null, null, 'The probe could not be completed: '.$e::class];
                }

                $manifested = null === $error && $expected !== $actual;
                $isActive = in_array($bug['bug_id'], $active, true);
                $results[] = [
                    'bug_id' => $bug['bug_id'],
                    'challenge_id' => $bug['challenge_id'],
                    'active' => $isActive,
                    'expected' => $expected,
                    'actual' => $actual,
                    'manifested' => $manifested,
                    // The run does what its switches say: defective where switched on, correct everywhere else.
                    'consistent' => null === $error && $manifested === $isActive,
                    'error' => $error,
                ];
            }
        } finally {
            if (null !== $outerRequest) {
                $this->requestStack->push($outerRequest);
            }
            $this->tokens->setToken($outerToken);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array{mixed, mixed} what a correct application answers, and what this run answered
     */
    private function probe(string $bugId, array $manifest): array
    {
        $shared = '/api/notes/'.$manifest['notes']['shared_1']['id'];

        switch ($bugId) {
            case BugCatalog::PAGINATION:
                $whole = array_column($this->call('owner', 'GET', '/api/notes?sortBy=createdAt&order=asc&limit=4')['json'], 'id');
                $second = array_column($this->call('owner', 'GET', '/api/notes?sortBy=createdAt&order=asc&limit=2&page=2')['json'], 'id');

                return [array_slice($whole, 2, 2), $second];

            case BugCatalog::AUTH_BYPASS:
                $note = $this->call('owner', 'GET', $shared)['json'];
                // Saving the text the note already has changes nothing even when it is let through.
                $status = $this->call('viewer', 'PUT', $shared, ['title' => $note['title'], 'content' => $note['content']])['status'];

                return [Response::HTTP_FORBIDDEN, $status];

            case BugCatalog::TAG_MATCH:
                $work = $manifest['tags']['owner']['work']['id'];
                $spare = $manifest['tags']['owner']['spare']['id'];
                $withWork = array_column($this->call('owner', 'GET', '/api/notes?limit=100&sortBy=createdAt&tagIds='.$work)['json'], 'id');
                $withEither = array_column($this->call('owner', 'GET', '/api/notes?limit=100&sortBy=createdAt&tagMatch=any&tagIds='.$work.','.$spare)['json'], 'id');

                // No fixture note carries "spare", so "any of the two" is exactly "work".
                return [count($withWork), count($withEither)];

            case BugCatalog::STALE_READ:
                $text = 'Oracle probe '.bin2hex(random_bytes(4));
                $this->call('owner', 'PATCH', '/api/notes/'.$manifest['notes']['private_2']['id'], ['content' => $text], ['HTTP_IF_MATCH' => '*']);
                $read = $this->call('owner', 'GET', '/api/notes/'.$manifest['notes']['private_2']['id'])['json']['content'] ?? null;

                return ['the text just saved', $read === $text ? 'the text just saved' : 'an earlier text'];

            case BugCatalog::SORTING:
                $reference = array_column($this->call('owner', 'GET', '/api/notes?limit=100&sort[title]=asc')['json'], 'title');
                $sorted = array_column($this->call('owner', 'GET', '/api/notes?limit=100&sortBy=title&order=asc')['json'], 'title');

                return ['A to Z', $sorted === $reference ? 'A to Z' : ($sorted === array_reverse($reference) ? 'Z to A' : 'another order')];

            case BugCatalog::STATUS_CODE:
                $created = $this->call('owner', 'POST', '/api/tasks', ['title' => '[oracle] status probe']);
                $this->discardTask($created);

                return [Response::HTTP_CREATED, $created['status']];

            case BugCatalog::DUPLICATE_NOTIFICATION:
                $note = $this->call('owner', 'POST', '/api/notes', ['title' => '[oracle] share probe', 'content' => 'probe'])['json'];
                $this->call('owner', 'POST', '/api/notes/'.$note['id'].'/shares', ['email' => $manifest['users']['unrelated']['email'], 'role' => 'viewer']);
                $notifications = (int) $this->entityManager->getConnection()->fetchOne(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND resource_id = ? AND type = 'share_granted'",
                    [$manifest['users']['unrelated']['id'], $note['id']],
                );
                $this->call('owner', 'DELETE', '/api/notes/'.$note['id']);

                return [1, $notifications];

            case BugCatalog::VALIDATION:
                $created = $this->call('owner', 'POST', '/api/tasks', ['title' => '[oracle] validation probe', 'description' => str_repeat('x', 5001)]);
                $this->discardTask($created);

                return [Response::HTTP_UNPROCESSABLE_ENTITY, $created['status']];

            case BugCatalog::TOTAL_COUNT:
                $owned = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM notes WHERE owner_id = ?', [$manifest['users']['owner']['id']]);
                $reported = (int) $this->call('owner', 'GET', '/api/notes?limit=1')['headers']->get('X-Total-Count');

                return [$owned, $reported];

            case BugCatalog::TRANSITION:
                $created = $this->call('owner', 'POST', '/api/tasks', ['title' => '[oracle] transition probe']);
                $status = $this->call('owner', 'POST', '/api/tasks/'.($created['json']['id'] ?? 'x').'/transition', ['status' => 'done'])['status'];
                $this->discardTask($created);

                return [Response::HTTP_CONFLICT, $status];
        }

        throw new \LogicException('No probe for '.$bugId);
    }

    /**
     * @param array{json: array<mixed>} $created
     */
    private function discardTask(array $created): void
    {
        if (isset($created['json']['id'])) {
            $this->call('owner', 'DELETE', '/api/tasks/'.$created['json']['id']);
        }
    }

    /**
     * Sends one request through the application as a persona of the run.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     *
     * @return array{status: int, json: array<mixed>, headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag}
     */
    private function call(string $persona, string $method, string $uri, ?array $body = null, array $server = []): array
    {
        // Each request starts as it would from outside: nothing loaded, nothing remembered.
        $this->entityManager->clear();
        $this->bugs->forget();
        $this->clock->forget();
        $user = $this->entityManager->find(User::class, $this->personas[$persona]->getId());

        $request = Request::create($uri, $method, [], [], [], $server + [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->jwt->create($user),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->entityManager->clear();

        $json = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'json' => is_array($json) ? $json : [], 'headers' => $response->headers];
    }
}
