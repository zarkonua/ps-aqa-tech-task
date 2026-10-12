<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Note;
use App\Entity\User;
use App\Service\NoteMutator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deleting a note removes its shares (FK cascade); the deletion and each removed share are audited first.
 */
final class NoteRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly NoteMutator $mutator,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Note) {
            $actor = $this->security->getUser();
            $this->mutator->auditDeletion($data, $actor instanceof User ? $actor : null);
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
