<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Note;
use App\Entity\NoteRevision;
use App\Entity\User;
use App\EventSubscriber\NoteEtagSubscriber;
use App\Service\NoteRevisions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

final class NotePersistProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly NoteRevisions $revisions,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Note) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authenticated user is required.');
        }

        $previous = $context['previous_data'] ?? null;
        if (!$previous instanceof Note) {
            return $this->create($data, $user, $operation, $uriVariables, $context);
        }

        $isPatch = $operation instanceof HttpOperation && 'PATCH' === $operation->getMethod();
        $ifMatch = $this->requestStack->getMainRequest()?->headers->get('If-Match');
        if ($isPatch && (null === $ifMatch || '' === trim($ifMatch))) {
            throw new PreconditionRequiredHttpException('Send the version this change is based on in an If-Match header.');
        }

        $expected = NoteRevisions::expectedVersion($ifMatch);
        if (null !== $expected && $expected !== $previous->getVersion()) {
            throw new PreconditionFailedHttpException('The note was changed since the version you edited. Reload it and apply your change again.');
        }

        // On PUT the payload is a fresh object whose properties are copied over the stored note,
        // so what the payload cannot carry must be kept explicitly: tags, category, the owner
        // (an editor saving a note must not become its owner), the version and the timestamps.
        $changed = $data->getTitle() !== $previous->getTitle() || $data->getContent() !== $previous->getContent();
        $data->keepTaxonomyOf($previous);
        $data->keepTimestampsOf($previous, !$changed);
        $data->setVersion($previous->getVersion());
        if (null !== $previous->getOwner()) {
            $data->setOwner($previous->getOwner());
        }

        // Saving the same text again is not a change: no new revision, nothing touched.
        if (!$changed) {
            return $this->withEtag($this->persistProcessor->process($data, $operation, $uriVariables, $context));
        }

        return $this->entityManager->wrapInTransaction(function () use ($data, $previous, $expected, $user, $operation, $uriVariables, $context): mixed {
            $from = $previous->getVersion();
            while (!$this->revisions->claimNext($previous, $from)) {
                // Somebody saved in between. With If-Match that is exactly what must not be overwritten;
                // without it the caller asked for last-write-wins.
                if (null !== $expected) {
                    throw new PreconditionFailedHttpException('The note was changed since the version you edited. Reload it and apply your change again.');
                }
                $from = $this->revisions->currentVersion($previous);
            }
            $data->setVersion($from + 1);

            $saved = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
            $this->revisions->record($saved, NoteRevision::UPDATED, $user);
            $this->entityManager->flush();

            return $this->withEtag($saved);
        });
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    private function create(Note $note, User $user, Operation $operation, array $uriVariables, array $context): mixed
    {
        if (null === $note->getOwner()) {
            $note->setOwner($user);
        }

        return $this->entityManager->wrapInTransaction(function () use ($note, $user, $operation, $uriVariables, $context): mixed {
            $saved = $this->persistProcessor->process($note, $operation, $uriVariables, $context);
            $this->revisions->record($saved, NoteRevision::CREATED, $user);
            $this->entityManager->flush();

            return $this->withEtag($saved);
        });
    }

    /**
     * Hands the saved note to NoteEtagSubscriber: after a PUT the request still holds the payload object.
     */
    private function withEtag(mixed $saved): mixed
    {
        if ($saved instanceof Note) {
            $this->requestStack->getMainRequest()?->attributes->set(NoteEtagSubscriber::ATTRIBUTE, $saved);
        }

        return $saved;
    }
}
