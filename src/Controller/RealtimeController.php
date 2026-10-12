<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Note;
use App\Entity\RealtimeEvent;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Security\NoteAccess;
use App\Service\Realtime;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Realtime')]
#[OA\Parameter(name: 'id', description: 'Note ID.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: Response::HTTP_UNAUTHORIZED, description: 'Missing, invalid or expired JWT. The token goes in the Authorization header, never in the URL.', content: new OA\JsonContent(ref: '#/components/schemas/JwtError'))]
#[OA\Response(response: Response::HTTP_NOT_FOUND, description: 'Note is missing or not visible to the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Note not found.']))]
#[OA\Response(response: Response::HTTP_INTERNAL_SERVER_ERROR, description: 'Internal server error.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError', example: ['error' => 'Internal server error.']))]
final class RealtimeController extends ApiController
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly NoteAccess $access,
        private readonly Realtime $realtime,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly JWTTokenManagerInterface $jwt,
        /** How long one stream response lasts before the client has to reconnect. 0: answer once and end. */
        #[Autowire('%app.realtime.stream_seconds%')]
        private readonly int $streamSeconds,
    ) {
    }

    #[Route('/api/realtime/notes/{id}/events', name: 'api_realtime_stream', methods: ['GET'])]
    #[OA\Get(
        summary: 'Stream what happens to a note (Server-Sent Events).',
        description: 'Everyone who can see the note. The stream starts with a "connected" event carrying the current version and who is present, then sends "note.updated", "note.deleted", "share.revoked", "presence.joined" and "presence.left" as they happen. It ends after about 25 seconds; reconnect with the last event ID in "Last-Event-ID" to receive what was missed. If that event is no longer kept, the first event is "resync": reload the note. Access is checked again every second; when it is lost the stream sends "share.revoked" and closes. Event IDs are opaque. Browsers must use fetch() with the Authorization header rather than EventSource, which cannot send one.',
    )]
    #[OA\Parameter(name: 'Last-Event-ID', description: 'ID of the last event received, to continue after it.', in: 'header', schema: new OA\Schema(type: 'string', example: '5c3d0a51f1e94b0b9d2a7e6c4f8b1a20'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'The event stream.', content: new OA\MediaType(mediaType: 'text/event-stream', schema: new OA\Schema(type: 'string', example: "retry: 1000\n\nid: 5c3d0a51f1e94b0b9d2a7e6c4f8b1a20\nevent: note.updated\ndata: {\"note_id\":\"…\",\"version\":4,\"actor\":{\"id\":\"…\",\"email\":\"editor@example.com\"}}\n\n")))]
    public function events(string $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED);
        }
        $note = $this->visibleNote($id, $user);
        if (null === $note) {
            return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
        }

        $noteId = (string) $note->getId();
        $userId = (string) $user->getId();
        $lastEventId = trim((string) $request->headers->get('Last-Event-ID', ''));
        $position = '' === $lastEventId ? null : $this->realtime->positionOf($noteId, $lastEventId);
        $resync = '' !== $lastEventId && null === $position;
        $expiresAt = $this->tokenExpiry();

        $response = new StreamedResponse(function () use ($noteId, $userId, $position, $resync, $expiresAt): void {
            @set_time_limit(0);
            while (ob_get_level() > 0 && 0 !== $this->streamSeconds) {
                @ob_end_flush();
            }
            $send = static function (string $event, array $data, ?string $eventId = null): void {
                echo (null === $eventId ? '' : 'id: '.$eventId."\n").'event: '.$event."\n".'data: '.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
                @flush();
            };

            echo "retry: 1000\n\n";
            $user = $this->entityManager->find(User::class, $userId);
            $note = $this->entityManager->find(Note::class, $noteId);
            $this->realtime->heartbeat($noteId, $user);
            // Without a known last event the client starts from "now"; everything before is in the note itself.
            $position ??= $this->realtime->latestPosition($noteId);
            $send($resync ? 'resync' : 'connected', [
                'note_id' => $noteId,
                'version' => $note->getVersion(),
                'presence' => $this->realtime->presence($noteId),
            ]);

            $endAt = time() + $this->streamSeconds;
            $lastHeartbeat = time();
            while (true) {
                foreach ($this->realtime->eventsAfter($noteId, $position, $user) as $event) {
                    $position = $event['position'];
                    $send($event['type'], ['note_id' => $noteId] + $event['data'] + ['created_at' => $event['created_at']], $event['id']);
                }

                // Permissions are not remembered from when the stream was opened.
                $this->entityManager->clear();
                $user = $this->entityManager->find(User::class, $userId);
                $note = $this->entityManager->find(Note::class, $noteId);
                if (null === $user || null === $note || !$this->access->canView($note, $user)) {
                    if (null !== $note) {
                        $send(RealtimeEvent::SHARE_REVOKED, ['note_id' => $noteId]);
                    }
                    break;
                }
                if (null !== $expiresAt && time() >= $expiresAt) {
                    $send('auth.expired', ['note_id' => $noteId]);
                    break;
                }

                if (time() - $lastHeartbeat >= 10) {
                    $this->realtime->heartbeat($noteId, $user);
                    $lastHeartbeat = time();
                    echo ": ping\n\n";
                    @flush();
                }
                if (time() >= $endAt || 0 !== connection_aborted()) {
                    break;
                }
                usleep(1_000_000);
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/api/realtime/notes/{id}/poll', name: 'api_realtime_poll', methods: ['GET'])]
    #[OA\Get(summary: 'Ask what happened to a note since an event (polling fallback).', description: 'The same events as the stream, as JSON, for clients that cannot keep a stream open. Each call also keeps the caller present for the next 30 seconds. Without "after", only the current state is returned.')]
    #[OA\Parameter(name: 'after', description: 'ID of the last event received.', in: 'query', schema: new OA\Schema(type: 'string', example: '5c3d0a51f1e94b0b9d2a7e6c4f8b1a20'))]
    #[OA\Response(response: Response::HTTP_OK, description: 'Events since "after", oldest first.', content: new OA\JsonContent(ref: '#/components/schemas/RealtimePoll'))]
    #[OA\Response(response: Response::HTTP_UNPROCESSABLE_ENTITY, description: '"after" is not an event ID.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    public function poll(string $id, Request $request): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id, $request): JsonResponse {
            $note = $this->visibleNote($id, $user);
            if (null === $note) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }

            $noteId = (string) $note->getId();
            $after = $request->query->all()['after'] ?? null;
            if (null !== $after && (!is_string($after) || 1 !== preg_match('/^[a-f0-9]{32}$/D', $after))) {
                return $this->validationError([['propertyPath' => 'after', 'message' => 'after must be an event ID received earlier.']]);
            }

            $this->realtime->heartbeat($noteId, $user);
            $position = null === $after ? null : $this->realtime->positionOf($noteId, $after);
            $resync = null !== $after && null === $position;
            $events = null === $position ? [] : $this->realtime->eventsAfter($noteId, $position, $user);

            if ([] !== $events) {
                $lastEventId = $events[count($events) - 1]['id'];
            } elseif (null !== $position) {
                $lastEventId = $after;
            } else {
                // Start (or start over) from the newest event, whoever it was for: its ID is only a bookmark.
                $lastEventId = $this->latestEventId($noteId);
            }

            return $this->json([
                'note_id' => $noteId,
                'version' => $note->getVersion(),
                'resync' => $resync,
                'events' => array_map(static fn (array $event): array => ['id' => $event['id'], 'type' => $event['type'], 'data' => (object) $event['data'], 'created_at' => $event['created_at']], $events),
                'last_event_id' => $lastEventId,
                'presence' => $this->realtime->presence($noteId),
            ]);
        });
    }

    #[Route('/api/realtime/notes/{id}/presence', name: 'api_realtime_leave', methods: ['DELETE'])]
    #[OA\Delete(summary: 'Say that I closed the note.', description: 'Optional: presence also ends by itself 30 seconds after the last stream heartbeat or poll.')]
    #[OA\Response(response: Response::HTTP_NO_CONTENT, description: 'No longer present.')]
    public function leave(string $id): JsonResponse
    {
        return $this->guarded(function (User $user) use ($id): JsonResponse {
            $note = $this->visibleNote($id, $user);
            if (null === $note) {
                return $this->error('Note not found.', Response::HTTP_NOT_FOUND);
            }
            $this->realtime->leave((string) $note->getId(), $user);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    private function visibleNote(string $id, User $user): ?Note
    {
        $note = Uuid::isValid($id) ? $this->notes->find($id) : null;

        return null !== $note && $this->access->canView($note, $user) ? $note : null;
    }

    private function latestEventId(string $noteId): ?string
    {
        $publicId = $this->entityManager->getConnection()->fetchOne('SELECT public_id FROM realtime_events WHERE note_id = ? ORDER BY id DESC LIMIT 1', [$noteId]);

        return false === $publicId ? null : $publicId;
    }

    /**
     * When the caller's token stops being valid: a stream must not outlive it.
     */
    private function tokenExpiry(): ?int
    {
        try {
            $token = $this->security->getToken();
            $payload = null === $token ? [] : $this->jwt->decode($token);
        } catch (\Throwable) {
            $payload = [];
        }

        return is_array($payload) && isset($payload['exp']) ? (int) $payload['exp'] : null;
    }
}
