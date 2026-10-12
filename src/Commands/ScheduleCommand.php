<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'schedule',
    description: 'Run ingestion on a schedule (useful with systemd timers / cron)',
    aliases: ['cron'],
    hidden: false,
)]
final class ScheduleCommand extends Command
{
    use LockableTrait;

    protected function configure(): void
    {
        $this
            ->addOption('sources', 's', InputOption::VALUE_OPTIONAL, 'Comma-separated subset of sources (default: all)')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Delay between requests in seconds (default: 1)', 1)
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing entries instead of skipping')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Print systemd timer + service unit files')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'In run-loop mode: interval between runs in seconds (default: 86400 = daily)', 86400);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('install')) {
            $this->printSystemdFiles($io);

            return Command::SUCCESS;
        }

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $sources = $input->getOption('sources');
        $interval = (int) $input->getOption('interval');

        $run = function () use ($input) {
            $cmd = $this->getApplication()->find('ingest:all');
            $args = [
                new \Symfony\Component\Console\Input\ArrayInput([
                    'command' => 'ingest:all',
                    '--delay' => (string) $input->getOption('delay'),
                    '--sources' => (string) $input->getOption('sources'),
                ] + ($input->getOption('update') ? ['--update' => true] : [])),
            ];
            $cmd->run($args[0], new \Symfony\Component\Console\Output\ConsoleOutput());
        };

        $io->section('Hiyori Scheduler');
        $io->text('Sources: ' . ($sources ?: 'all'));
        $io->text('Interval: ' . $interval . 's' . ($interval >= 86400 ? ' (daily)' : ''));

        /** @phpstan-ignore-next-line */
        while (true) {
            $start = microtime(true);
            $io->info('[' . date('c') . '] Starting scheduled run');
            $run();
            $elapsed = microtime(true) - $start;
            $io->info(sprintf('[%s] Run finished in %.1fs. Next run in %ds', date('c'), $elapsed, $interval));
            sleep($interval);
        }
    }

    private function printSystemdFiles(SymfonyStyle $io): void
    {
        $io->section('Systemd Timer + Service (recommended for production)');

        $io->text('1. Create /etc/systemd/system/hiyori-ingest.service:');
        $io->block(<<<'UNIT'
            [Unit]
            Description=Hiyori DB ingestion
            After=network-online.target mongod.service
            Wants=network-online.target

            [Service]
            Type=oneshot
            User=nekomata
            WorkingDirectory=/home/nekomata/projects/hiyori-cli
            ExecStart=/usr/bin/php hiyori schedule --sources anilist,kitsu,myanimelist --delay 1
            UNIT, 'comment');

        $io->newLine();
        $io->text('2. Create /etc/systemd/system/hiyori-ingest.timer:');
        $io->block(<<<'TIMER'
            [Unit]
            Description=Run Hiyori DB ingestion daily
            Requires=hiyori-ingest.service

            [Timer]
            OnCalendar=daily
            RandomizedDelaySec=600
            Persistent=true

            [Install]
            WantedBy=timers.target
            TIMER, 'comment');

        $io->newLine();
        $io->text('3. Enable and start:');
        $io->block('sudo systemctl daemon-reload
sudo systemctl enable --now hiyori-ingest.timer
sudo systemctl list-timers hiyori-ingest', 'comment');

        $io->newLine();
        $io->block('Or add to crontab (quick option):
0 3 * * * cd /home/nekomata/projects/hiyori-cli && /usr/bin/php hiyori ingest:all --delay 1 >> /var/log/hiyori-ingest.log 2>&1', 'comment');
    }
}
