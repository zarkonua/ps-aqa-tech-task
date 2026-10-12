<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountToken;
use App\Entity\AuditEvent;
use App\Entity\User;
use App\Repository\AccountTokenRepository;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use App\Service\AuditLogger;
use App\Service\PasswordPolicy;
use App\Service\Throttle;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[OA\Tag(name: 'Account')]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class AccountController extends ApiController
{
    public const EMAIL_TOKEN_TTL_MINUTES = 60;
    public const WINDOW_SECONDS = 900;
    public const WRONG_PASSWORDS_PER_USER = 5;
    public const EMAIL_CHANGES_PER_USER = 5;
    public const FAILED_CONFIRMS_PER_IP = 10;

    private const PASSWORD_BUCKET = 'account_password_check';
    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly AccountMailer $mailer,
        private readonly Throttle $throttle,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/account/profile', name: 'api_account_profile', methods: ['GET'])]
    #[OA\Get(summary: 'Get my profile.')]
    #[OA\Response(response: Response::HTTP_OK, description: 'Profile.', content: new OA\JsonContent(ref: '#/components/schemas/AccountProfile'))]
    public function profile(): JsonResponse
    {
        return $this->guarded(fn (User $user): JsonResponse => $this->json($this->profileData($user)));
    }

    #[Route('/api/account/profile', name: 'api_account_profile_update', methods: ['PATCH'])]
    #[OA\Patch(summary: 'Ask to change my email address.', description: 'The sign-in email does not change yet: a confirmation link (valid 60 minutes, works once) is sent to the new address, and the change happens when it is confirmed. The answer is the same whether or not the address is already used by another account.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['email', 'current_password'],
        properties: [
            new OA\Property(property: 'email', description: 'New email address.', type: 'string', format: 'email', maxLength: 190, example: 'new@example.com'),
            new OA\Property(property: 'current_password', type: 'string', example: 'my current password'),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_ACCEPTED, description: 'Confirmation requested; the profile still shows the current email.', content: new OA\JsonContent(ref: '#/components/schemas/AccountProfile'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Current password is wrong.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Current password is incorrect.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Validation failed, e.g. malformed email or the same as the current one.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    #[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'Too many wrong passwords or email change requests.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
    public function updateProfile(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['email', 'current_password']);
            $email = $payload['email'] ?? null;
            if (!is_string($email) || '' === trim($email)) {
                $violations[] = ['propertyPath' => 'email', 'message' => is_string($email) || null === $email ? 'Email is required.' : 'Email must be a string.'];
            } elseif (strlen($email) > 190 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                $violations[] = ['propertyPath' => 'email', 'message' => 'Invalid email format.'];
            } elseif (strtolower(trim($email)) === $user->getEmail()) {
                $violations[] = ['propertyPath' => 'email', 'message' => 'This is already your email address.'];
            }
            $this->requireString($payload, 'current_password', 'Current password', $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $denied = $this->checkCurrentPassword($user, $payload['current_password']);
            if (null !== $denied) {
                return $denied;
            }

            $retryAfter = $this->throttle->attempt('account_email_change', (string) $user->getId(), self::EMAIL_CHANGES_PER_USER, self::WINDOW_SECONDS);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $email = strtolower(trim($email));
            $plainToken = bin2hex(random_bytes(32));
            $this->tokens->revokePending($user, AccountToken::EMAIL_CHANGE);
            $this->entityManager->persist(new AccountToken(
                $user,
                AccountToken::EMAIL_CHANGE,
                $plainToken,
                new \DateTimeImmutable(sprintf('+%d minutes', self::EMAIL_TOKEN_TTL_MINUTES)),
                $email,
            ));
            $this->audit->record(AuditEvent::EMAIL_CHANGE_REQUESTED, $user, null, targetUserId: $user->getId());
            $this->entityManager->flush();

            // An address that already has an account gets no mail; the confirmation could never succeed.
            if (null === $this->users->findOneByEmail($email)) {
                $this->mailer->sendEmailChange(
                    $email,
                    $this->generateUrl('app_main', ['email_change_token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL),
                    self::EMAIL_TOKEN_TTL_MINUTES,
                );
            }

            return $this->json($this->profileData($user), Response::HTTP_ACCEPTED);
        });
    }

    #[Route('/api/account/email/confirm', name: 'api_account_email_confirm', methods: ['POST'])]
    #[OA\Post(summary: 'Confirm a new email address.', description: 'Public: the token from the confirmation link is the proof. On success the account signs in with the new email and every existing session is signed out. Limit: 10 failed attempts per client address in 15 minutes.', security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['token'],
        properties: [new OA\Property(property: 'token', description: 'Token from the confirmation link.', type: 'string', example: '3f6c0f5a…')],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Email changed.', content: new OA\JsonContent(ref: '#/components/schemas/Message', example: ['message' => 'Email address changed. Sign in with your new email.']))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_CONFLICT, description: 'The address was taken by another account in the meantime.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'This email address is no longer available.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'The token is missing, invalid, expired or already used.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    #[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'Too many failed attempts from this address.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
    public function confirmEmail(Request $request): JsonResponse
    {
        return $this->guardedPublic(function () use ($request): JsonResponse {
            $ip = (string) $request->getClientIp();
            $retryAfter = $this->throttle->retryAfter('account_email_confirm_ip', $ip, self::FAILED_CONFIRMS_PER_IP, self::WINDOW_SECONDS);
            if (null !== $retryAfter) {
                return $this->tooManyRequests($retryAfter);
            }

            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['token']);
            $this->requireString($payload, 'token', 'Token', $violations);
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $token = 1 === preg_match(self::TOKEN_PATTERN, $payload['token']) ? $this->tokens->findByPlainToken(AccountToken::EMAIL_CHANGE, $payload['token']) : null;
            $problem = match (true) {
                null === $token, null !== $token->getUsedAt() => 'This confirmation link is invalid or was already used.',
                $token->isExpired() => 'This confirmation link has expired. Request the change again.',
                !$this->tokens->consume($token) => 'This confirmation link is invalid or was already used.',
                default => null,
            };
            if (null !== $problem) {
                $this->throttle->record('account_email_confirm_ip', $ip);

                return $this->validationError([['propertyPath' => 'token', 'message' => $problem]]);
            }

            $user = $token->getUser();
            if (null !== $this->users->findOneByEmail((string) $token->getPayload())) {
                return $this->error('This email address is no longer available.', Response::HTTP_CONFLICT);
            }

            $user->setEmail((string) $token->getPayload());
            $user->invalidateTokens();
            $this->audit->record(AuditEvent::EMAIL_CHANGED, $user, null, targetUserId: $user->getId());

            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                return $this->error('This email address is no longer available.', Response::HTTP_CONFLICT);
            }

            return $this->json(['message' => 'Email address changed. Sign in with your new email.']);
        });
    }

    #[Route('/api/account/change-password', name: 'api_account_change_password', methods: ['POST'])]
    #[OA\Post(summary: 'Change my password.', description: 'Needs the current password. On success every other session is signed out and a new token for this session is returned. Limit: 5 wrong current passwords in 15 minutes.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['current_password', 'new_password'],
        properties: [
            new OA\Property(property: 'current_password', type: 'string', example: 'my current password'),
            new OA\Property(property: 'new_password', type: 'string', maxLength: 128, minLength: 12, example: 'correct horse battery'),
        ],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Password changed.', content: new OA\JsonContent(
        required: ['message', 'token'],
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Password changed.'),
            new OA\Property(property: 'token', description: 'JWT to use from now on; the one sent with this request no longer works.', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...'),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_FORBIDDEN, description: 'Current password is wrong.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Current password is incorrect.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Weak new password, or the same as the current one.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'new_password', 'message' => 'Password must be at least 12 characters.']]]))]
    #[OA\Response(response: Response::HTTP_TOO_MANY_REQUESTS, description: 'Too many wrong current passwords.', content: new OA\JsonContent(ref: '#/components/schemas/RateLimitError'))]
    public function changePassword(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['current_password', 'new_password']);
            $this->requireString($payload, 'current_password', 'Current password', $violations);
            $passwordViolation = PasswordPolicy::violation($payload['new_password'] ?? null);
            if (null !== $passwordViolation) {
                $violations[] = ['propertyPath' => 'new_password', 'message' => $passwordViolation];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $denied = $this->checkCurrentPassword($user, $payload['current_password']);
            if (null !== $denied) {
                return $denied;
            }

            if ($payload['new_password'] === $payload['current_password']) {
                return $this->validationError([['propertyPath' => 'new_password', 'message' => 'New password must differ from the current one.']]);
            }

            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $payload['new_password']));
            $user->invalidateTokens();
            $this->tokens->revokePending($user, AccountToken::PASSWORD_RESET);
            $this->audit->record(AuditEvent::PASSWORD_CHANGED, $user, null, targetUserId: $user->getId());
            $this->entityManager->flush();

            return $this->json(['message' => 'Password changed.', 'token' => $this->jwt->create($user)]);
        });
    }

    #[Route('/api/account/settings', name: 'api_account_settings', methods: ['GET'])]
    #[OA\Get(summary: 'Get my settings.')]
    #[OA\Response(response: Response::HTTP_OK, description: 'Settings.', content: new OA\JsonContent(ref: '#/components/schemas/AccountSettings'))]
    public function settings(): JsonResponse
    {
        return $this->guarded(fn (User $user): JsonResponse => $this->json(['timezone' => $user->getTimezone()]));
    }

    #[Route('/api/account/settings', name: 'api_account_settings_update', methods: ['PATCH'])]
    #[OA\Patch(summary: 'Change my settings.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['timezone'],
        properties: [new OA\Property(property: 'timezone', description: 'IANA time zone name, exactly as in the tz database (case-sensitive), or "UTC".', type: 'string', example: 'Europe/Kyiv')],
        additionalProperties: false,
    ))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Settings saved.', content: new OA\JsonContent(ref: '#/components/schemas/AccountSettings'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Unknown time zone or other validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError', example: ['error' => 'Validation failed.', 'violations' => [['propertyPath' => 'timezone', 'message' => 'Time zone must be an IANA name such as Europe/Kyiv or UTC.']]]))]
    public function updateSettings(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['timezone']);
            $timezone = $payload['timezone'] ?? null;
            if (null === $timezone) {
                $violations[] = ['propertyPath' => 'timezone', 'message' => 'Time zone is required.'];
            } elseif (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                $violations[] = ['propertyPath' => 'timezone', 'message' => 'Time zone must be an IANA name such as Europe/Kyiv or UTC.'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            if ($timezone !== $user->getTimezone()) {
                $user->setTimezone($timezone);
                $this->audit->record(AuditEvent::SETTINGS_UPDATED, $user, null, targetUserId: $user->getId(), metadata: ['changed' => ['timezone']]);
                $this->entityManager->flush();
            }

            return $this->json(['timezone' => $user->getTimezone()]);
        });
    }

    /**
     * @return JsonResponse|null the refusal (403 or 429), or null when the password is right
     */
    private function checkCurrentPassword(User $user, string $password): ?JsonResponse
    {
        $key = (string) $user->getId();
        $retryAfter = $this->throttle->retryAfter(self::PASSWORD_BUCKET, $key, self::WRONG_PASSWORDS_PER_USER, self::WINDOW_SECONDS);
        if (null !== $retryAfter) {
            return $this->tooManyRequests($retryAfter);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->throttle->record(self::PASSWORD_BUCKET, $key);

            return $this->error('Current password is incorrect.', Response::HTTP_FORBIDDEN);
        }

        $this->throttle->clear(self::PASSWORD_BUCKET, $key);

        return null;
    }

    /**
     * @param array<string, mixed>                               $payload
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    private function requireString(array $payload, string $field, string $label, array &$violations): void
    {
        $value = $payload[$field] ?? null;
        if (null === $value || '' === $value) {
            $violations[] = ['propertyPath' => $field, 'message' => $label.' is required.'];
        } elseif (!is_string($value)) {
            $violations[] = ['propertyPath' => $field, 'message' => $label.' must be a string.'];
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function profileData(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'pending_email' => $this->tokens->findPending($user, AccountToken::EMAIL_CHANGE)?->getPayload(),
        ];
    }
}
