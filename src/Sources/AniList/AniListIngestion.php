<?php

namespace Hiyori\Sources\AniList;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\AniListBase;
use Hiyori\Models\Anime\Base\KitsuBase;
use Hiyori\Sources\AniList\Requests\AniListEntryList;
use Hiyori\Sources\AniList\Requests\AniListEntryListMeta;
use Hiyori\Sources\SourceConfiguration;


class AniListIngestion
{
    private AniListEntryListMeta $meta;
    private AniListEntryList $list;
    private SourceConfiguration $config;
    public function __construct(
        SourceConfiguration $sourceConfiguration
    )
    {
        $this->config = $sourceConfiguration;


        $this->config->io->info('Populating AniList Index...');
        $this->meta = AniListEntryListMeta::create();
        $this->meta->setCurrentPage(0);

        $progressBar = $this->config->io->createProgressBar($this->meta->getTotalEntries());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();


        while ($this->meta->getCurrentPage() <= $this->meta->getTotalEntries()) {
            $currentPage = $this->meta->getCurrentPage();
            $this->list = AniListEntryList::create($currentPage);

            foreach ($this->list->getList() as $listItem) {

                if ($this->exists($listItem['id'])) {
                    $progressBar->advance();
                    continue;
                }

                $model = AniListBase::create($listItem);
                $this->save($model);
                $progressBar->advance();
            }

            $this->meta->incrementCurrentPage();
            sleep($this->config->input->getOption('delay'));
        }

        $progressBar->finish();
    }

    public function exists(string $id): bool
    {
        $response = $this->config->client->hiyori->anilist
            ->findOne(['reference_ids.al' => $id]);

        return !($response === null);
    }

    public function save(AniListBase $entry): self
    {
        $entry = $this->config->serializer->serialize($entry, 'json');
        $this->config->client->hiyori->anilist
            ->insertOne(Helper::toArray($entry));

        return $this;
    }
}