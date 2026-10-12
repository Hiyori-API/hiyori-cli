<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'backup',
    description: 'Backup the hiyori database using mongodump',
    aliases: ['dump'],
    hidden: false,
)]
final class BackupCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output archive path (default: storage/backups/hiyori-<timestamp>.gz)', null)
            ->addOption('collections', 'c', InputOption::VALUE_OPTIONAL, 'Comma-separated collections to back up (default: all)')
            ->addOption('gzip', 'g', InputOption::VALUE_NONE, 'Gzip-compress the archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mongoUrl = $_ENV['MONGODB_URL'] ?? 'mongodb://localhost:27017';
        $gzip = $input->getOption('gzip');

        $timestamp = date('Ymd-His');
        $outputPath = $input->getOption('output')
            ?? __DIR__ . '/../../storage/backups/hiyori-' . $timestamp . ($gzip ? '.gz' : '');

        $backupDir = dirname($outputPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0o777, true);
        }

        $collectionsOpt = $input->getOption('collections');

        $cmd = ['mongodump'];
        $cmd[] = '--uri=' . $mongoUrl;
        $cmd[] = '--db=hiyori';
        if ($gzip) {
            $cmd[] = '--gzip';
        }
        if ($collectionsOpt) {
            foreach (explode(',', $collectionsOpt) as $collection) {
                $cmd[] = '--collection=' . trim($collection);
            }
        }
        $cmd[] = '--archive=' . $outputPath;

        $io->section('Backup');
        $io->text('Command: ' . implode(' ', $cmd));
        $io->text('Output: ' . $outputPath);

        $proc = proc_open($cmd, [
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            $io->error('Failed to start mongodump');

            return Command::FAILURE;
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $io->error('Backup failed (exit ' . $exitCode . '): ' . $stderr);

            return Command::FAILURE;
        }

        $size = file_exists($outputPath) ? round(filesize($outputPath) / 1024 / 1024, 2) : 0;
        $io->success("Backup complete: $outputPath ($size MB)");

        return Command::SUCCESS;
    }
}
