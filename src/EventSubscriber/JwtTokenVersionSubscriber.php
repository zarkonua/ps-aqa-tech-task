<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Session invalidation for stateless JWTs: each token carries the user's token version,
 * and a token whose version is not the current one is rejected. Changing or resetting
 * the password raises the version, which signs the user out everywhere.
 */
final class JwtTokenVersionSubscriber implements EventSubscriberInterface
{
    public const CLAIM = 'ver';

    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_CREATED => 'onCreated',
            Events::JWT_DECODED => 'onDecoded',
        ];
    }

    public function onCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $event->setData($event->getData() + [self::CLAIM => $user->getTokenVersion()]);
        }
    }

    public function onDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $email = $payload['username'] ?? null;
        $user = is_string($email) ? $this->users->findOneByEmail($email) : null;

        // Tokens issued before versions existed carry no claim and count as version 0.
        if (null !== $user && (int) ($payload[self::CLAIM] ?? 0) !== $user->getTokenVersion()) {
            $event->markAsInvalid();
        }
    }
}
