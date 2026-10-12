<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Note;
use App\Service\NoteRevisions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Responses that carry one note also carry its version as an ETag,
 * which clients send back in If-Match when they save.
 */
final class NoteEtagSubscriber implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_saved_note';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        $data = $attributes->get(self::ATTRIBUTE) ?? $attributes->get('data');
        if ($event->isMainRequest() && $data instanceof Note && $event->getResponse()->isSuccessful() && null !== $data->getId()) {
            $event->getResponse()->headers->set('ETag', NoteRevisions::etag($data));
        }
    }
}
