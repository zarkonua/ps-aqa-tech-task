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
     * Queues an audit record; it is written by the caller's flush, in the same
     * transaction as the change it describes.
     *
     * @param array<string, mixed> $metadata must not contain secrets or personal data
     */
    public function record(
        string $eventType,
        ?User $actor,
        string $noteId,
        ?string $shareId = null,
        ?string $targetUserId = null,
        array $metadata = [],
    ): AuditEvent {
        $correlationId = $this->requestStack->getMainRequest()?->attributes->get(CorrelationIdSubscriber::ATTRIBUTE);

        $event = new AuditEvent(
            $eventType,
            $actor?->getId(),
            $noteId,
            $shareId,
            $targetUserId,
            $metadata,
            is_string($correlationId) ? $correlationId : null,
        );
        $this->entityManager->persist($event);

        $this->logger->info('audit: {event}', [
            'event' => $eventType,
            'actor_id' => $event->getActorId(),
            'note_id' => $noteId,
            'share_id' => $shareId,
            'target_user_id' => $targetUserId,
            'correlation_id' => $event->getCorrelationId(),
        ]);

        return $event;
    }
}
