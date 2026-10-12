<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ingest:all',
    description: 'Run ingestion for all available sources sequentially',
    aliases: ['ia'],
    hidden: false,
)]
final class IngestAllCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Delay between requests in seconds (default: 1)', 1)
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing entries instead of skipping')
            ->addOption('sources', 's', InputOption::VALUE_OPTIONAL, 'Comma-separated subset of sources (default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $delay = (int) $input->getOption('delay');
        $update = (bool) $input->getOption('update');
        $sourcesOpt = $input->getOption('sources');

        $allSources = ['myanimelist', 'kitsu', 'anilist', 'myanimelist_manga', 'kitsu_manga', 'anilist_manga'];
        $sources = $sourcesOpt ? array_map('trim', explode(',', $sourcesOpt)) : $allSources;

        $started = microtime(true);
        $io->section('Ingest All');
        $io->text('Sources: ' . implode(', ', $sources));
        $io->text('Delay: ' . $delay . 's | Update: ' . ($update ? 'yes' : 'no'));

        $results = [];
        foreach ($sources as $source) {
            if (!in_array($source, $allSources, true)) {
                $io->warning("Unknown source: $source (skipped)");
                $results[$source] = 'skipped';
                continue;
            }

            $start = microtime(true);
            $io->newLine();
            $io->info("Starting ingestion: $source");

            try {
                $this->hiyori->ingest($source, [
                    'delay' => $delay,
                    'update' => $update,
                ]);
                $results[$source] = sprintf('ok (%.1fs)', microtime(true) - $start);
            } catch (\Throwable $e) {
                $io->error("[$source] " . $e->getMessage());
                $results[$source] = 'error: ' . $e->getMessage();
            }
        }

        $io->newLine();
        $io->section('Ingest All Summary');
        foreach ($results as $source => $result) {
            $io->text("  $source: $result");
        }
        $io->success(sprintf('Total time: %.1fs', microtime(true) - $started));

        $this->release();

        return Command::SUCCESS;
    }
}
