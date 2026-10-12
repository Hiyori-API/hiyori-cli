<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ingest',
    description: 'Begins ingestion from selected source',
    aliases: ['i'],
    hidden: false,
)]
final class IngestionCommand extends Command
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
            ->addArgument('source', InputArgument::REQUIRED, 'Source to ingest from (myanimelist, anilist, kitsu)')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Delay between requests in seconds (default: 1)', 1)
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing entries instead of skipping')
            ->addOption('resume-page', 'r', InputOption::VALUE_OPTIONAL, 'Resume from specific page number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getArgument('source');

        // Lock per source (not per command) so different sources can ingest in parallel.
        if (!$this->lock('ingest-' . $source)) {
            $output->writeln("The ingestion for '{$source}' is already running in another process.");

            return Command::SUCCESS;
        }

        $delay = (int) $input->getOption('delay');
        $update = $input->getOption('update');
        $resumePage = $input->getOption('resume-page') ? (int) $input->getOption('resume-page') : null;

        $this->hiyori->ingest($source, [
            'delay' => $delay,
            'update' => $update,
            'resume_page' => $resumePage,
        ]);

        $this->release();

        return Command::SUCCESS;
    }
}
