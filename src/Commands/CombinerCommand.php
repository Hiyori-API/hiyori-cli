<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Models\Common\Base as AnimeBaseModel;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntry;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntryList;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntryListMeta;
use Hiyori\Sources\SourceConfig;
use MongoDB\Client;
use MongoDB\InsertOneResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'combine',
    description: 'Combine metadata from all sources into one',
    aliases: ['c', 'combiner'],
    hidden: false
)]
final class CombinerCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private Hiyori $hiyori
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('base', InputArgument::REQUIRED, 'Source dataset to use as a base')
            ->addOption('strategy', 's', InputOption::VALUE_OPTIONAL, 'Defaults to RelationalMapping');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $this->hiyori->combine(
            $input->getArgument('base'),
            $input->getOption('strategy') ?? RelationalMappingStrategy::NAME
        );


        $this->release();

        return Command::SUCCESS;
    }

}
