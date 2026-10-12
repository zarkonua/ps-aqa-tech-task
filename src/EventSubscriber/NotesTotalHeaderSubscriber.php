<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The notes list is a plain JSON array; the size of the whole result
 * (only notes the caller may see) is reported in response headers.
 */
final class NotesTotalHeaderSubscriber implements EventSubscriberInterface
{
    public const TOTAL = 'X-Total-Count';
    public const PAGE = 'X-Page';
    public const LIMIT = 'X-Limit';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $data = $event->getRequest()->attributes->get('data');
        if (!$event->isMainRequest() || !$data instanceof PaginatorInterface || !$event->getResponse()->isSuccessful()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set(self::TOTAL, (string) (int) $data->getTotalItems());
        $headers->set(self::PAGE, (string) (int) $data->getCurrentPage());
        $headers->set(self::LIMIT, (string) (int) $data->getItemsPerPage());
    }
}
