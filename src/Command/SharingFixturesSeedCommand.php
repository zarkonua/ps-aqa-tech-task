<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RunManager;
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
    description: 'Seeds the personas, notes, shares, tags and categories for a run ID and prints their manifest as JSON.',
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
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for all personas.', SharingFixtures::DEFAULT_PASSWORD)
            ->addOption('fixture', null, InputOption::VALUE_REQUIRED, 'Fixture set: '.implode(', ', array_keys(RunManager::FIXTURES)).'.', 'full')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed that varies the generated content.', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('prod' === $this->environment) {
            $output->writeln('<error>Fixtures cannot be seeded in the prod environment.</error>');

            return Command::FAILURE;
        }

        $fixture = (string) $input->getOption('fixture');
        $seed = filter_var($input->getOption('seed'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        if (!isset(RunManager::FIXTURES[$fixture]) || false === $seed) {
            $output->writeln('<error>Unknown --fixture, or --seed is not a whole number from 1 to 1000000.</error>');

            return Command::INVALID;
        }

        try {
            $manifest = ['fixture' => $fixture] + $this->fixtures->seed((string) $input->getArgument('run-id'), (string) $input->getOption('password'), $seed, RunManager::FIXTURES[$fixture]);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
