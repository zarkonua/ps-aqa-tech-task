<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\EventSubscriber\CorrelationIdSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Last line of defence: whatever a caller passes, credentials never reach the audit table.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public static function withoutSecrets(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (1 === preg_match('/pass(word|phrase)?|token|secret|authorization|cookie|jwt|credential/i', (string) $key)) {
                unset($metadata[$key]);
            } elseif (is_array($value)) {
                $metadata[$key] = self::withoutSecrets($value);
            }
        }

        return $metadata;
    }

    /**
     * Queues an audit record; it is written by the caller's flush, in the same
     * transaction as the change it describes.
     *
     * @param array<string, mixed> $metadata must not contain secrets or personal data
     */
    public function record(
        string $eventType,
        ?User $actor,
        ?string $noteId,
        ?string $shareId = null,
        ?string $targetUserId = null,
        array $metadata = [],
        ?string $subjectId = null,
    ): AuditEvent {
        $correlationId = $this->requestStack->getMainRequest()?->attributes->get(CorrelationIdSubscriber::ATTRIBUTE);
        $metadata = self::withoutSecrets($metadata);

        $event = new AuditEvent(
            $eventType,
            $actor?->getId(),
            $noteId,
            $shareId,
            $targetUserId,
            $metadata,
            is_string($correlationId) ? $correlationId : null,
            $subjectId,
        );
        $this->entityManager->persist($event);

        $this->logger->info('audit: {event}', [
            'event' => $eventType,
            'actor_id' => $event->getActorId(),
            'note_id' => $noteId,
            'share_id' => $shareId,
            'subject_id' => $subjectId,
            'target_user_id' => $targetUserId,
            'correlation_id' => $event->getCorrelationId(),
        ]);

        return $event;
    }
}
