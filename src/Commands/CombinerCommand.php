<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Models\Common\Base as AnimeBaseModel;
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


        $baseSource = $input->getArgument('base');
        $io = new SymfonyStyle($input, $output);

        $client = new Client('mongodb://localhost:27017');


        $io->section('Preflight');
        if ($this)
        die;



        $this->config->io->info('Populating MyAnimeList Index...');
        $this->meta = MyAnimeListEntryListMeta::create();
        $this->meta->setCurrentPage(1);

        $progressBar = $this->config->io->createProgressBar($this->meta->getTotalEntries());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();


        while ($this->meta->getCurrentPage() <= $this->meta->getLastPage()) {
            $currentPage = $this->meta->getCurrentPage();
            $this->list = MyAnimeListEntryList::create($currentPage);

            foreach ($this->list->getList() as $listItem) {

                if ($this->exists($listItem['mal_id'])) {
                    $progressBar->advance();
                    continue;
                }

                sleep($this->config->input->getOption('delay'));

                try {
                    $listItemData = MyAnimeListEntry::create($listItem['mal_id']);
                } catch (\Exception $e) {
                    $this->config->io->error('429 on '.$listItem['mal_id']);
                }


                $this->save(MyAnimeListBase::create($listItemData->getData()));
                $progressBar->advance();
            }

            $this->meta->incrementCurrentPage();
            sleep($this->config->input->getOption('delay'));
        }

        $progressBar->finish();


        $this->release();

        return Command::SUCCESS;
    }

    public function exists(string $id): bool
    {
        $response = $this->config->client->hiyori->myanimelist
            ->findOne(['reference_ids.mal' => $id]);

        return !($response === null);
    }

    public function save(AnimeBaseModel $entry): InsertOneResult
    {
        $entry = $this->config->serializer->serialize($entry, 'json');
        return $this->config->client->hiyori->myanimelist->insertOne(Helper::toArray($entry));
    }
}
