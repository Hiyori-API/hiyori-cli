<?php

namespace Hiyori\Sources\Kitsu;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\KitsuBase;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryList;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryListMeta;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryMappings;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryStreamingLinks;
use Hiyori\Sources\SourceConfiguration;


class KitsuIngestion
{
    private KitsuEntryListMeta $meta;
    private KitsuEntryList $list;
    private SourceConfiguration $config;
    public function __construct(
        SourceConfiguration $sourceConfiguration
    )
    {
        $this->config = $sourceConfiguration;


        $this->config->io->info('Populating Kitsu Index...');
        $this->meta = KitsuEntryListMeta::create();
        $this->meta->setCurrentPage(0);

        $progressBar = $this->config->io->createProgressBar($this->meta->getTotalEntries());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();


        while ($this->meta->getCurrentPage() <= $this->meta->getTotalEntries()) {
            $currentPage = $this->meta->getCurrentPage();
            $this->list = KitsuEntryList::create($currentPage);

            foreach ($this->list->getList() as $listItem) {

                if ($this->exists($listItem['id'])) {
                    $progressBar->advance();
                    continue;
                }

                sleep($this->config->input->getOption('delay'));

                try {
                    $mappings = KitsuEntryMappings::create($listItem['id']);
                    $streamingLinks = KitsuEntryStreamingLinks::create($listItem['id']);

                    $listItemData = array_merge($listItem, ['streaming'=>$streamingLinks->getData()], ['external'=>$mappings->getData()]);

                } catch (\Exception $e) {
                    $this->config->io->error('429 on '.$listItem['id']);
                }

                $model = KitsuBase::create($listItemData);
                $this->save($model);
                $progressBar->advance();
            }

            $this->meta->setCurrentPage($this->meta->getCurrentPage()+20);
            sleep($this->config->input->getOption('delay'));
        }

        $progressBar->finish();
    }

    public function exists(string $id): bool
    {
        $response = $this->config->client->hiyori->kitsu
            ->findOne(['reference_ids.k' => $id]);

        return !($response === null);
    }

    public function save(KitsuBase $entry): self
    {
        $entry = $this->config->serializer->serialize($entry, 'json');
        $this->config->client->hiyori->kitsu
            ->insertOne(Helper::toArray($entry));

        return $this;
    }
}