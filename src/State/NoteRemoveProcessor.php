<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuditEvent;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteShareRepository;
use App\Service\AuditLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deleting a note removes its shares (FK cascade); each removed share is audited first.
 */
final class NoteRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly NoteShareRepository $shares,
        private readonly AuditLogger $audit,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Note) {
            $actor = $this->security->getUser();
            foreach ($this->shares->findByNote($data) as $share) {
                $this->audit->record(
                    AuditEvent::SHARE_REMOVED_WITH_NOTE,
                    $actor instanceof User ? $actor : null,
                    (string) $data->getId(),
                    $share->getId(),
                    $share->getUser()->getId(),
                    ['role' => $share->getRole()->value],
                );
            }
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
