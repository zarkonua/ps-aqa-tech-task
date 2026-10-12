<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SharingFixtures;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:fixtures:sharing:purge',
    description: 'Removes the notes-sharing fixtures of a run ID.',
)]
final class SharingFixturesPurgeCommand extends Command
{
    public function __construct(
        private readonly SharingFixtures $fixtures,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run-id', InputArgument::REQUIRED, 'Run ID used when seeding.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $removed = $this->fixtures->purge((string) $input->getArgument('run-id'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(sprintf('Removed %d persona(s) and their notes, shares and audit records.', $removed));

        return Command::SUCCESS;
    }
}
