<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:user:admin',
    description: 'Makes a user an administrator (who may read the full audit feed), or takes that away with --revoke.',
)]
final class UserAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of an existing user.')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Remove the administrator role instead of granting it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $user) {
            $output->writeln('<error>No user with this email.</error>');

            return Command::FAILURE;
        }

        $user->setIsAdmin(!$input->getOption('revoke'));
        $this->entityManager->flush();
        $output->writeln(sprintf('%s is %s an administrator.', $user->getEmail(), $user->isAdmin() ? 'now' : 'no longer'));

        return Command::SUCCESS;
    }
}
