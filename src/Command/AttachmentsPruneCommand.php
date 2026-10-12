<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NoteAttachment;
use App\Service\AttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:attachments:prune',
    description: 'Removes stored attachment files that no attachment record refers to any more.',
)]
final class AttachmentsPruneCommand extends Command
{
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $known = array_flip(array_column(
            $this->entityManager->createQueryBuilder()->select('a.storageKey')->from(NoteAttachment::class, 'a')->getQuery()->getScalarResult(),
            'storageKey',
        ));
        $orphans = array_values(array_filter($this->storage->keys(), static fn (string $key): bool => !isset($known[$key])));
        $this->storage->delete($orphans);

        $output->writeln(sprintf('Removed %d orphaned file(s).', count($orphans)));

        return Command::SUCCESS;
    }
}
