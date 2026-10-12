<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Notifier;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Notifications')]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class NotificationController extends ApiController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const MESSAGES = [
        Notification::SHARE_GRANTED => '%s shared a note with you',
        Notification::SHARE_REVOKED => '%s removed your access to a note',
        Notification::TASK_ASSIGNED => '%s assigned a task to you',
        Notification::TASK_DUE => 'A task is due soon',
        Notification::TASK_COMPLETED => '%s completed a task',
    ];
    private const LINKS = [
        Notification::SHARE_GRANTED => '/account/shared',
        Notification::SHARE_REVOKED => null,
        Notification::TASK_ASSIGNED => '/account/tasks',
        Notification::TASK_DUE => '/account/tasks',
        Notification::TASK_COMPLETED => '/account/tasks',
    ];

    public function __construct(
        private readonly Notifier $notifier,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notifications', name: 'api_notifications_list', methods: ['GET'])]
    #[OA\Get(summary: 'List my notifications.', description: 'Newest first. Notifications are kept for 90 days. Titles of notes and tasks are included only while I can still see them.')]
    #[OA\Parameter(name: 'unread', description: '"true" lists only unread notifications.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['true', 'false']))]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Notifications.', content: new OA\JsonContent(
        required: ['items', 'total', 'unread', 'page', 'items_per_page'],
        properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Notification')),
            new OA\Property(property: 'total', description: 'Notifications matching the filter.', type: 'integer', example: 4),
            new OA\Property(property: 'unread', description: 'All my unread notifications, whatever the filter.', type: 'integer', example: 2),
            new OA\Property(property: 'page', type: 'integer', example: 1),
            new OA\Property(property: 'items_per_page', type: 'integer', example: 20),
        ],
    ))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Invalid filter or pagination value.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function list(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $violations = [];
            $page = $this->validatedQueryInt($request, 'page', 1, 1, PHP_INT_MAX, $violations);
            $limit = $this->validatedQueryInt($request, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, $violations);
            $unread = $request->query->all()['unread'] ?? 'false';
            if (!in_array($unread, ['true', 'false'], true)) {
                $violations[] = ['propertyPath' => 'unread', 'message' => 'unread must be "true" or "false".'];
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            $this->refresh($user);

            $criteria = ['user' => $user] + ('true' === $unread ? ['readAt' => null] : []);
            $repository = $this->entityManager->getRepository(Notification::class);

            return $this->json([
                'items' => $this->notificationData($repository->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, ($page - 1) * $limit)),
                'total' => $repository->count($criteria),
                'unread' => $repository->count(['user' => $user, 'readAt' => null]),
                'page' => $page,
                'items_per_page' => $limit,
            ]);
        });
    }

    #[Route('/api/notifications/unread-count', name: 'api_notifications_unread_count', methods: ['GET'], priority: 10)]
    #[OA\Get(summary: 'Count my unread notifications.')]
    #[OA\Response(response: Response::HTTP_OK, description: 'Unread count.', content: new OA\JsonContent(required: ['unread'], properties: [new OA\Property(property: 'unread', type: 'integer', example: 2)]))]
    public function unreadCount(): JsonResponse
    {
        return $this->guarded(function (User $user): JsonResponse {
            $this->refresh($user);

            return $this->json(['unread' => $this->entityManager->getRepository(Notification::class)->count(['user' => $user, 'readAt' => null])]);
        });
    }

    #[Route('/api/notifications/read-all', name: 'api_notifications_read_all', methods: ['POST'], priority: 10)]
    #[OA\Post(summary: 'Mark all my notifications as read.')]
    #[OA\Response(response: Response::HTTP_OK, description: 'Done.', content: new OA\JsonContent(
        required: ['updated', 'unread'],
        properties: [
            new OA\Property(property: 'updated', description: 'How many were unread until now.', type: 'integer', example: 2),
            new OA\Property(property: 'unread', type: 'integer', example: 0),
        ],
    ))]
    public function readAll(): JsonResponse
    {
        return $this->guarded(function (User $user): JsonResponse {
            $updated = $this->entityManager->getConnection()->executeStatement(
                'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
                [gmdate('Y-m-d H:i:s'), $user->getId()],
            );

            return $this->json(['updated' => (int) $updated, 'unread' => 0]);
        });
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notifications_read', methods: ['POST'])]
    #[OA\Post(summary: 'Mark one notification as read.', description: 'Marking a notification that is already read changes nothing and succeeds.')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The notification, read.', content: new OA\JsonContent(ref: '#/components/schemas/Notification'))]
    #[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'No such notification among mine.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Notification not found.']))]
    public function read(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $notification = Uuid::isValid($id) ? $this->entityManager->getRepository(Notification::class)->findOneBy(['id' => $id, 'user' => $user]) : null;
            if (null === $notification) {
                return $this->error('Notification not found.', Response::HTTP_NOT_FOUND);
            }

            // Only the first call sets the time; replays leave it as it is.
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE notifications SET read_at = ? WHERE id = ? AND read_at IS NULL',
                [gmdate('Y-m-d H:i:s'), $notification->getId()],
            );
            $this->entityManager->refresh($notification);

            return $this->json($this->notificationData([$notification])[0]);
        });
    }

    #[Route('/api/account/notification-preferences', name: 'api_notification_preferences', methods: ['GET'])]
    #[OA\Get(summary: 'Get my notification email preferences.')]
    #[OA\Response(response: Response::HTTP_OK, description: 'Preferences.', content: new OA\JsonContent(ref: '#/components/schemas/NotificationPreferences'))]
    public function preferences(): JsonResponse
    {
        return $this->guarded(fn (User $user): JsonResponse => $this->json($this->preferenceData($user)));
    }

    #[Route('/api/account/notification-preferences', name: 'api_notification_preferences_update', methods: ['PATCH'])]
    #[OA\Patch(summary: 'Choose which notifications are also sent by email.', description: 'Send the types to change. This only affects notification emails: sign-up, password reset and email change messages are always sent, and in-app notifications are always created.')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NotificationPreferences'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Preferences saved.', content: new OA\JsonContent(ref: '#/components/schemas/NotificationPreferences'))]
    #[OA\Response(response: Response::HTTP_BAD_REQUEST, description: 'Body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Invalid JSON payload.']))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: 'Unknown notification type, or a value that is not true or false.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function updatePreferences(Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($request): JsonResponse {
            $payload = $this->objectPayload($request);
            $violations = $this->unexpectedFields($payload, ['email']);
            $email = $payload['email'] ?? null;
            if (!is_array($email) || ([] !== $email && array_is_list($email))) {
                $violations[] = ['propertyPath' => 'email', 'message' => 'An object of notification types and true/false values is required.'];
                $email = [];
            }
            foreach ($email as $type => $wanted) {
                if (!in_array($type, Notification::TYPES, true)) {
                    $violations[] = ['propertyPath' => 'email.'.mb_substr((string) $type, 0, 40), 'message' => 'Unknown notification type.'];
                } elseif (!is_bool($wanted)) {
                    $violations[] = ['propertyPath' => 'email.'.$type, 'message' => 'Must be true or false.'];
                }
            }
            if ([] !== $violations) {
                return $this->validationError($violations);
            }

            foreach ($email as $type => $wanted) {
                $user->setWantsEmailFor($type, $wanted);
            }
            $this->entityManager->flush();

            return $this->json($this->preferenceData($user));
        });
    }

    /**
     * Brings the user's notifications up to date before they are read: due tasks in, expired ones out.
     */
    private function refresh(User $user): void
    {
        $this->notifier->taskDue($user);
        $this->notifier->prune($user);
    }

    /**
     * @return array{email: array<string, bool>}
     */
    private function preferenceData(User $user): array
    {
        $email = [];
        foreach (Notification::TYPES as $type) {
            $email[$type] = $user->wantsEmailFor($type);
        }

        return ['email' => $email];
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return list<array<string, mixed>>
     */
    private function notificationData(array $notifications): array
    {
        $actorIds = array_values(array_unique(array_filter(array_map(static fn (Notification $n): ?string => $n->getActorId(), $notifications))));
        $emails = [];
        foreach ([] === $actorIds ? [] : $this->users->findBy(['id' => $actorIds]) as $actor) {
            $emails[$actor->getId()] = $actor->getEmail();
        }
        $utc = new \DateTimeZone('UTC');

        return array_map(function (Notification $notification) use ($emails, $utc): array {
            $actorEmail = null === $notification->getActorId() ? null : ($emails[$notification->getActorId()] ?? null);
            $resource = $this->notifier->resource($notification);
            $message = sprintf(self::MESSAGES[$notification->getType()], $actorEmail ?? 'Someone');
            // A revoked share never names the note, and nothing else does once it is out of reach.
            if (null !== $resource['title'] && Notification::SHARE_REVOKED !== $notification->getType()) {
                $message .= sprintf(': "%s"', $resource['title']);
            } else {
                $resource['title'] = null;
            }

            return [
                'id' => $notification->getId(),
                'type' => $notification->getType(),
                'message' => $message.'.',
                'actor' => null === $notification->getActorId() ? null : ['id' => $notification->getActorId(), 'email' => $actorEmail],
                'resource' => $resource,
                'link' => $resource['available'] ? self::LINKS[$notification->getType()] : null,
                'created_at' => $notification->getCreatedAt()->setTimezone($utc)->format(\DATE_ATOM),
                'read_at' => $notification->getReadAt()?->setTimezone($utc)->format(\DATE_ATOM),
            ];
        }, $notifications);
    }
}
