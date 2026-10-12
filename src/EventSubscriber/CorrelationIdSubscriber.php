<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Util\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives every API request a correlation ID: the client's X-Correlation-ID when it is
 * well-formed, a generated one otherwise. It is echoed in the response and stored on audit records.
 */
final class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Correlation-ID';
    public const ATTRIBUTE = '_correlation_id';

    private const PATTERN = '/^[A-Za-z0-9._-]{8,64}$/D';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 255],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $provided = (string) $request->headers->get(self::HEADER, '');
        $request->attributes->set(
            self::ATTRIBUTE,
            1 === preg_match(self::PATTERN, $provided) ? $provided : Uuid::v4(),
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        $correlationId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($event->isMainRequest() && is_string($correlationId)) {
            $event->getResponse()->headers->set(self::HEADER, $correlationId);
        }
    }
}
