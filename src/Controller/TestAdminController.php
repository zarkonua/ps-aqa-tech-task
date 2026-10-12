<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TestRun;
use App\Service\RunConflict;
use App\Service\RunManager;
use App\Service\SharingFixtures;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The test-data API for whoever runs the tests: create, inspect, reset and remove isolated runs.
 * It is not part of the application's own API: it has its own tokens (never a user's JWT), is left out
 * of the public API documentation, and does not exist at all in production or without a configured token.
 */
final class TestAdminController extends ApiController
{
    public function __construct(
        private readonly RunManager $runs,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(default::TEST_ADMIN_TOKEN)%')]
        private readonly ?string $adminToken,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/test-admin', name: 'test_admin_ui', methods: ['GET'])]
    public function ui(): Response
    {
        $this->assertEnabled();

        return $this->render('test_admin/index.html.twig');
    }

    #[Route('/test-admin/openapi.json', name: 'test_admin_openapi', methods: ['GET'])]
    public function openApi(Request $request): JsonResponse
    {
        return $this->authorized($request, null, fn (): JsonResponse => JsonResponse::fromJsonString((string) file_get_contents($this->projectDir.'/docs/test-admin-openapi.json')));
    }

    #[Route('/test-admin/fixtures', name: 'test_admin_fixtures', methods: ['GET'])]
    public function fixtures(Request $request): JsonResponse
    {
        return $this->authorized($request, null, fn (): JsonResponse => $this->json([
            'fixture_version' => SharingFixtures::VERSION,
            'fixtures' => array_map(static fn (string $name, array $parts): array => ['name' => $name, 'parts' => $parts], array_keys(RunManager::FIXTURES), RunManager::FIXTURES),
        ]));
    }

    #[Route('/test-admin/runs', name: 'test_admin_runs', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->authorized($request, null, fn (): JsonResponse => $this->json([
            'items' => array_map(self::runData(...), $this->runs->all()),
            'max' => RunManager::MAX_RUNS,
        ]));
    }

    #[Route('/test-admin/runs', name: 'test_admin_runs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->authorized($request, null, function () use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['fixture', 'seed', 'run_id']);
            $fixture = $payload['fixture'] ?? 'full';
            if (!is_string($fixture) || !isset(RunManager::FIXTURES[$fixture])) {
                $violations[] = ['propertyPath' => 'fixture', 'message' => 'fixture must be one of: '.implode(', ', array_keys(RunManager::FIXTURES)).'.'];
            }
            $seed = $payload['seed'] ?? 1;
            if (!is_int($seed) || $seed < 1 || $seed > 1_000_000) {
                $violations[] = ['propertyPath' => 'seed', 'message' => 'seed must be a whole number from 1 to 1000000.'];
            }
            $runId = $payload['run_id'] ?? null;
            if (null !== $runId) {
                try {
                    SharingFixtures::assertValidRunId(is_string($runId) ? $runId : '');
                } catch (\InvalidArgumentException $e) {
                    $violations[] = ['propertyPath' => 'run_id', 'message' => $e->getMessage()];
                }
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $created = $this->runs->create($fixture, $seed, $runId);

            return $this->json(
                self::runData($created['run']) + ['run_token' => $created['token'], 'manifest' => $created['run']->getManifest()],
                Response::HTTP_CREATED,
                ['Location' => '/test-admin/runs/'.$created['run']->getId()],
            );
        });
    }

    #[Route('/test-admin/runs/{id}', name: 'test_admin_run', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        return $this->authorized($request, $id, static fn (TestRun $run): JsonResponse => new JsonResponse(self::runData($run)));
    }

    #[Route('/test-admin/runs/{id}/manifest', name: 'test_admin_run_manifest', methods: ['GET'])]
    public function manifest(string $id, Request $request): JsonResponse
    {
        return $this->authorized($request, $id, fn (TestRun $run): JsonResponse => $this->json(self::runData($run) + ['manifest' => $run->getManifest()]));
    }

    #[Route('/test-admin/runs/{id}/reset', name: 'test_admin_run_reset', methods: ['POST'])]
    public function reset(string $id, Request $request): JsonResponse
    {
        return $this->authorized($request, $id, function (TestRun $run): JsonResponse {
            $reset = $this->runs->reset($run);

            return $this->json(self::runData($reset['run']) + ['mail_deleted' => $reset['mail_deleted'], 'manifest' => $reset['run']->getManifest()]);
        });
    }

    #[Route('/test-admin/runs/{id}/advance-clock', name: 'test_admin_run_clock', methods: ['POST'])]
    public function advanceClock(string $id, Request $request): JsonResponse
    {
        return $this->authorized($request, $id, function (TestRun $run) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['seconds']);
            $seconds = $payload['seconds'] ?? null;
            if (!is_int($seconds) || $seconds < 1 || $seconds > RunManager::MAX_CLOCK_OFFSET) {
                $violations[] = ['propertyPath' => 'seconds', 'message' => sprintf('seconds must be a whole number from 1 to %d. A clock only goes forward; reset the run to go back.', RunManager::MAX_CLOCK_OFFSET)];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            return $this->json(self::runData($this->runs->advanceClock($run, $seconds)));
        });
    }

    #[Route('/test-admin/runs/{id}', name: 'test_admin_run_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        return $this->authorized($request, $id, function (TestRun $run): JsonResponse {
            $this->runs->delete($run);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    private function assertEnabled(): void
    {
        // Indistinguishable from a route that was never there.
        if ('prod' === $this->environment || null === $this->adminToken || '' === $this->adminToken) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Checks the caller's token and runs the action. The administrator token may do everything;
     * a run's own token may only touch that run.
     *
     * @param string|null $runId the run concerned, or null for actions that need the administrator token
     */
    private function authorized(Request $request, ?string $runId, \Closure $action): JsonResponse
    {
        $this->assertEnabled();

        return $this->guardedPublic(function () use ($request, $runId, $action): JsonResponse {
            $header = (string) $request->headers->get('Authorization', '');
            $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
            if ('' === $token) {
                return $this->json(['error' => 'A test-admin token is required.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
            }
            $isAdmin = hash_equals((string) $this->adminToken, $token);

            if (null === $runId) {
                if (!$isAdmin) {
                    return $this->denied($token);
                }

                try {
                    return $action();
                } catch (RunConflict $e) {
                    return $this->conflict($e);
                }
            }

            $run = 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $runId) ? $this->runs->find($runId) : null;
            if (!$isAdmin) {
                // A run token says which run it belongs to only by matching it.
                if (null !== $run && hash_equals($run->getTokenHash(), hash('sha256', $token))) {
                    try {
                        return $action($run);
                    } catch (RunConflict $e) {
                        return $this->conflict($e);
                    }
                }

                return $this->denied($token);
            }
            if (null === $run) {
                return $this->error('Run not found.', Response::HTTP_NOT_FOUND);
            }

            try {
                return $action($run);
            } catch (RunConflict $e) {
                return $this->conflict($e);
            }
        });
    }

    /**
     * A token that is some run's token is known but not allowed here (403); anything else is unknown (401).
     */
    private function denied(string $token): JsonResponse
    {
        $hash = hash('sha256', $token);
        foreach ($this->runs->all() as $run) {
            if (hash_equals($run->getTokenHash(), $hash)) {
                return $this->error('This token only gives access to its own run.', Response::HTTP_FORBIDDEN);
            }
        }

        return $this->json(['error' => 'Invalid test-admin token.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }

    private function conflict(RunConflict $conflict): JsonResponse
    {
        return $this->error($conflict->getMessage(), $conflict->quota ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function runData(TestRun $run): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            'run_id' => $run->getId(),
            'fixture' => $run->getFixture(),
            'fixture_version' => $run->getFixtureVersion(),
            'seed' => $run->getSeed(),
            'manifest_hash' => $run->getManifestHash(),
            'resets' => $run->getResets(),
            'clock_offset_seconds' => $run->getClockOffsetSeconds(),
            'clock_now' => (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $run->getClockOffsetSeconds()))->setTimezone($utc)->format(\DATE_ATOM),
            'created_at' => $run->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
            'updated_at' => $run->getUpdatedAt()->setTimezone($utc)->format(\DATE_ATOM),
        ];
    }
}
