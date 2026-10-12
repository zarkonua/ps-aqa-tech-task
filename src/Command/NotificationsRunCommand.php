<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Notifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:notifications:run',
    description: 'Creates "task due" notifications for all users and removes notifications older than 90 days. Safe to run as often as wanted.',
)]
final class NotificationsRunCommand extends Command
{
    public function __construct(
        private readonly Notifier $notifier,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Created %d due notification(s), removed %d old notification(s).', $this->notifier->taskDue(), $this->notifier->prune()));

        return Command::SUCCESS;
    }
}
