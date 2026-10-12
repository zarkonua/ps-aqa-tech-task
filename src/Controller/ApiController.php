<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

abstract class ApiController extends AbstractController
{
    private ?LoggerInterface $logger = null;

    protected function payload(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid JSON payload.');
        }

        if (!is_array($data)) {
            throw new \RuntimeException('JSON payload must be an object.');
        }

        return $data;
    }

    protected function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Runs an action for the authenticated user and turns failures into the documented responses:
     * 400 for a body that is not a JSON object, 500 (without internal details) for anything unexpected.
     *
     * @param \Closure(User): JsonResponse $action
     */
    protected function guarded(\Closure $action): JsonResponse
    {
        return $this->guardedPublic(function () use ($action): JsonResponse {
            $user = $this->getUser();
            if (!$user instanceof User) {
                return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED);
            }

            return $action($user);
        });
    }

    /**
     * Same failure handling as guarded(), for routes that need no signed-in user.
     *
     * @param \Closure(): JsonResponse $action
     */
    protected function guardedPublic(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (MalformedPayloadException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger?->error('API request failed: {exception}', ['exception' => $e::class, 'controller' => static::class]);

            return $this->error('Internal server error.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function objectPayload(Request $request): array
    {
        try {
            $payload = $this->payload($request);
        } catch (\RuntimeException $e) {
            throw new MalformedPayloadException($e->getMessage());
        }

        if ([] !== $payload && array_is_list($payload)) {
            throw new MalformedPayloadException('JSON payload must be an object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowed
     *
     * @return list<array{propertyPath: string, message: string}>
     */
    protected function unexpectedFields(array $payload, array $allowed): array
    {
        $violations = [];
        foreach (array_diff(array_keys($payload), $allowed) as $field) {
            $violations[] = ['propertyPath' => mb_substr((string) $field, 0, 64), 'message' => 'Unexpected field.'];
        }

        return $violations;
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    protected function validatedVersion(array $payload, array &$violations): ?int
    {
        if (!array_key_exists('version', $payload)) {
            return null;
        }

        $version = $payload['version'];
        if (!is_int($version) || $version < 1) {
            $violations[] = ['propertyPath' => 'version', 'message' => 'Version must be a positive integer.'];

            return null;
        }

        return $version;
    }

    /**
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    protected function validatedQueryInt(Request $request, string $name, int $default, int $min, int $max, array &$violations): int
    {
        if (!$request->query->has($name)) {
            return $default;
        }

        $value = filter_var($request->query->all()[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if (false === $value) {
            $violations[] = [
                'propertyPath' => $name,
                'message' => PHP_INT_MAX === $max
                    ? sprintf('%s must be an integer of at least %d.', $name, $min)
                    : sprintf('%s must be an integer between %d and %d.', $name, $min, $max),
            ];

            return $default;
        }

        return $value;
    }

    protected function tooManyRequests(int $retryAfter): JsonResponse
    {
        return $this->json(
            ['error' => 'Too many attempts. Try again later.', 'retry_after' => $retryAfter],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }

    /**
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    protected function validationError(array $violations): JsonResponse
    {
        return $this->json(['error' => 'Validation failed.', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
