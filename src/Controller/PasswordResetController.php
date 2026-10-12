<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountToken;
use App\Entity\AuditEvent;
use App\Repository\AccountTokenRepository;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use App\Service\AuditLogger;
use App\Service\PasswordPolicy;
use App\Service\RunClock;
use App\Service\Throttle;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[OA\Tag(name: 'Password Reset')]
#[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
#[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'Too many attempts for this email or from this address.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class PasswordResetController extends ApiController
{
    public const TOKEN_TTL_MINUTES = 30;
    public const WINDOW_SECONDS = 900;
    public const REQUESTS_PER_EMAIL = 3;
    public const REQUESTS_PER_IP = 20;
    public const FAILED_CONFIRMS_PER_IP = 10;
    public const GENERIC_MESSAGE = 'If an account exists for this email, a password reset link has been sent.';

    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AccountMailer $mailer,
        private readonly Throttle $throttle,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
        private readonly RunClock $clock,
    ) {
    }

    #[Route('/api/auth/password-reset/request', name: 'api_password_reset_request', methods: ['POST'])]
    #[OA\Post(summary: 'Ask for a password reset link.', description: 'Public. The answer is the same whether or not the email belongs to an account. A confirmed account gets an email with a link that is valid for 30 minutes and works once; asking again makes earlier links stop working. Limits: 3 requests per email and 20 per client address in 15 minutes.', security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['email'],
        properties: [new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 190, example: 'user@example.com')],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_ACCEPTED, description: 'Request accepted (says nothing about whether the account exists).', content: new OA\JsonContent(ref: '#/components/schemas/Message', example: ['message' => self::GENERIC_MESSAGE]))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Missing or malformed email.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function request(Request $request): JsonResponse
    {
        return $this->guardedPublic(function () use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['email']);
            $email = $payload['email'] ?? null;
            if (!is_string($email) || '' === trim($email)) {
                $violations[] = ['propertyPath' => 'email', 'message' => is_string($email) || null === $email ? 'Email is required.' : 'Email must be a string.'];
            } elseif (strlen($email) > 190 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                $violations[] = ['propertyPath' => 'email', 'message' => 'Invalid email format.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }
            $email = strtolower(trim($email));

            // Counted for any email, known or not, so the limit itself reveals nothing either.
            $retryAfter = $this->throttle->attempt('password_reset_request_ip', (string) $request->getClientIp(), self::REQUESTS_PER_IP, self::WINDOW_SECONDS)
                ?? $this->throttle->attempt('password_reset_request_email', $email, self::REQUESTS_PER_EMAIL, self::WINDOW_SECONDS);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $user = $this->users->findOneByEmail($email);
            if (null !== $user && $user->isVerified()) {
                $plainToken = bin2hex(random_bytes(32));
                $this->tokens->revokePending($user, AccountToken::PASSWORD_RESET);
                $this->entityManager->persist(new AccountToken(
                    $user,
                    AccountToken::PASSWORD_RESET,
                    $plainToken,
                    new \DateTimeImmutable(sprintf('+%d minutes', self::TOKEN_TTL_MINUTES)),
                ));
                $this->audit->record(AuditEvent::PASSWORD_RESET_REQUESTED, $user, null, targetUserId: $user->getId());
                $this->entityManager->flush();

                $this->mailer->sendPasswordReset(
                    $user->getEmail(),
                    $this->generateUrl('app_main', ['reset_token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL),
                    self::TOKEN_TTL_MINUTES,
                );
            }

            return $this->json(['message' => self::GENERIC_MESSAGE], Response::HTTP_ACCEPTED);
        });
    }

    #[Route('/api/auth/password-reset/confirm', name: 'api_password_reset_confirm', methods: ['POST'])]
    #[OA\Post(summary: 'Set a new password with a reset token.', description: 'Public. On success the token is used up and every existing session of the account is signed out; sign in with the new password. Limit: 10 failed attempts per client address in 15 minutes.', security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['token', 'password'],
        properties: [
            new OA\Property(property: 'token', description: 'Token from the reset link.', type: 'string', example: '3f6c0f5a…'),
            new OA\Property(property: 'password', type: 'string', maxLength: 128, minLength: 12, example: 'correct horse battery'),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Password updated.', content: new OA\JsonContent(ref: '#/components/schemas/Message', example: ['message' => 'Password updated. Sign in with your new password.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Weak password, or the token is invalid, expired or already used.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'token', 'message' => 'This reset link has expired. Request a new one.']]]))]
    public function confirm(Request $request): JsonResponse
    {
        return $this->guardedPublic(function () use ($request): JsonResponse {
            $ip = (string) $request->getClientIp();
            $retryAfter = $this->throttle->retryAfter('password_reset_confirm_ip', $ip, self::FAILED_CONFIRMS_PER_IP, self::WINDOW_SECONDS);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['token', 'password']);
            $plainToken = $payload['token'] ?? null;
            if (!is_string($plainToken) || '' === $plainToken) {
                $violations[] = ['propertyPath' => 'token', 'message' => is_string($plainToken) || null === $plainToken ? 'Token is required.' : 'Token must be a string.'];
            }
            $passwordViolation = PasswordPolicy::violation($payload['password'] ?? null);
            if (null !== $passwordViolation) {
                $violations[] = ['propertyPath' => 'password', 'message' => $passwordViolation];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $token = 1 === preg_match(self::TOKEN_PATTERN, $plainToken) ? $this->tokens->findByPlainToken(AccountToken::PASSWORD_RESET, $plainToken) : null;
            $problem = match (true) {
                null === $token, null !== $token->getUsedAt() => 'This reset link is invalid or was already used. Request a new one.',
                $token->isExpired($this->clock->now($token->getUser())) => 'This reset link has expired. Request a new one.',
                // Two requests with the same token: only one of them gets to use it.
                !$this->tokens->consume($token) => 'This reset link is invalid or was already used. Request a new one.',
                default => null,
            };
            if (null !== $problem) {
                $this->throttle->record('password_reset_confirm_ip', $ip);

                return $this->validationError([['propertyPath' => 'token', 'message' => $problem]]);
            }

            $user = $token->getUser();
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $payload['password']));
            $user->invalidateTokens();
            $this->audit->record(AuditEvent::PASSWORD_RESET_COMPLETED, $user, null, targetUserId: $user->getId());
            $this->entityManager->flush();

            return $this->json(['message' => 'Password updated. Sign in with your new password.']);
        });
    }
}
