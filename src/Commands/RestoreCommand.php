<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'restore',
    description: 'Restore the hiyori database from an archive using mongorestore',
    aliases: ['restore:db'],
    hidden: false,
)]
final class RestoreCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Path to the backup archive (.gz for gzipped)')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Drop collections before restoring');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $archive = $input->getArgument('archive');
        $mongoUrl = $_ENV['MONGODB_URL'] ?? 'mongodb://localhost:27017';

        if (!file_exists($archive)) {
            $io->error("Archive not found: $archive");

            return Command::FAILURE;
        }

        $isGzip = str_ends_with(strtolower($archive), '.gz');

        $cmd = ['mongorestore'];
        $cmd[] = '--uri=' . $mongoUrl;
        if ($isGzip) {
            $cmd[] = '--gzip';
        }
        if ($input->getOption('drop')) {
            $cmd[] = '--drop';
        }
        $cmd[] = '--archive=' . $archive;

        $io->section('Restore');
        $io->text('Command: ' . implode(' ', $cmd));
        $io->warning('This will overwrite existing data in the hiyori database.');

        $io->text('Proceeding with restore...');
        $proc = proc_open($cmd, [
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            $io->error('Failed to start mongorestore');

            return Command::FAILURE;
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $io->error('Restore failed (exit ' . $exitCode . '): ' . $stderr);

            return Command::FAILURE;
        }

        $io->success('Restore complete from ' . $archive);

        return Command::SUCCESS;
    }
}
