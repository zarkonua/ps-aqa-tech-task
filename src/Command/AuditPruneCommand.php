<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:audit:prune',
    description: 'Removes audit records older than the retention period (365 days unless --days is given).',
)]
final class AuditPruneCommand extends Command
{
    public const RETENTION_DAYS = 365;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep records of the last this many days.', (string) self::RETENTION_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $days) {
            $output->writeln('<error>--days must be a positive number.</error>');

            return Command::INVALID;
        }

        $removed = $this->connection->executeStatement('DELETE FROM audit_events WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        $output->writeln(sprintf('Removed %d audit record(s) older than %d days.', $removed, $days));

        return Command::SUCCESS;
    }
}
