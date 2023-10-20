<?php

namespace Hiyori\Commands;

use Hiyori\App;
use Hiyori\Sources\MyAnimeList\MyAnimeListIngestion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'indexer:anime',
    description: 'Begins indexing from selected source',
    aliases: ['i:a'],
    hidden: false
)]
final class AnimeIndexerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Source to index')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new App($input, $output))
            ->ingest($input->getArgument('source'));

        return Command::SUCCESS;
    }
}