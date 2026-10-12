<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SharingFixtures;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:fixtures:sharing:seed',
    description: 'Seeds the notes-sharing personas and notes for a run ID and prints their manifest as JSON.',
)]
final class SharingFixturesSeedCommand extends Command
{
    public function __construct(
        private readonly SharingFixtures $fixtures,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::REQUIRED, 'Run ID that isolates this fixture set (lowercase letters, digits, dashes; max 32).')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for all personas.', SharingFixtures::DEFAULT_PASSWORD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('prod' === $this->environment) {
            $output->writeln('<error>Fixtures cannot be seeded in the prod environment.</error>');

            return Command::FAILURE;
        }

        try {
            $manifest = $this->fixtures->seed((string) $input->getArgument('run-id'), (string) $input->getOption('password'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
