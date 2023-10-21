<?php

namespace Hiyori\Sources\MyAnimeList;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Models\Common\Base as AnimeBaseModel;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntry;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntryList;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntryListMeta;
use Hiyori\Sources\SourceConfig;
use MongoDB\InsertOneResult;


class MyAnimeListIngestion
{
    private MyAnimeListEntryListMeta $meta;
    private MyAnimeListEntryList $list;
    private SourceConfig $config;
    public function __construct(
        SourceConfig $sourceConfiguration
    )
    {
        $this->config = $sourceConfiguration;


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