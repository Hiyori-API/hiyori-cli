<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migrate',
    description: 'Run pending database migrations (indexes, collections)',
    aliases: ['m'],
)]
final class MigrateCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only report pending migrations (exit 1 when any are pending)')
            ->addOption('upto', null, InputOption::VALUE_REQUIRED, 'Apply migrations up to this version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var Migrator $migrator */
        $migrator = $this->hiyori->getMigrator();
        $pending = $migrator->pending();

        if ($input->getOption('check')) {
            if ($pending === []) {
                $io->success('No pending migrations — DB is up to date.');

                return Command::SUCCESS;
            }
            foreach ($pending as $v => $name) {
                $io->writeln(sprintf('  pending v%s: %s', $v, $name));
            }
            $io->error(sprintf('%d migration(s) pending — run: php hiyori migrate', count($pending)));

            return Command::FAILURE;
        }

        if ($pending === []) {
            $io->success('No pending migrations — DB is up to date.');

            return Command::SUCCESS;
        }

        $upto = $input->getOption('upto') ? (int) $input->getOption('upto') : null;
        $applied = $migrator->run($upto);

        $io->success(sprintf('Applied %d migration(s):', count($applied)));
        foreach ($applied as $v => $name) {
            $io->writeln(sprintf('  ✓ v%s %s', $v, $name));
        }

        return Command::SUCCESS;
    }
}
