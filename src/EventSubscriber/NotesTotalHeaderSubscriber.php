<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use ApiPlatform\State\Pagination\PaginatorInterface;
use App\Service\BugCatalog;
use App\Service\BugSwitch;
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

    public function __construct(
        private readonly BugSwitch $bugs,
    ) {
    }

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
        // Injected defect BUG-009, only for test runs that have it switched on.
        $headers->set(self::TOTAL, (string) ((int) $data->getTotalItems() + ($this->bugs->isActive(BugCatalog::TOTAL_COUNT) ? 1 : 0)));
        $headers->set(self::PAGE, (string) (int) $data->getCurrentPage());
        $headers->set(self::LIMIT, (string) (int) $data->getItemsPerPage());
    }
}
