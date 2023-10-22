<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Requests\MyAnimeList\MyAnimeListEntry;
use Hiyori\Requests\MyAnimeList\MyAnimeListEntryList;
use Hiyori\Requests\MyAnimeList\MyAnimeListEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;

final class MyAnimeListIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void
    {
        $meta = MyAnimeListEntryListMeta::create();
        $config = $sources->get();

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        while ($meta->getCurrentPage() <= $meta->getLastPage()) {
            $currentPage = $meta->getCurrentPage();
            $list = MyAnimeListEntryList::create($currentPage);

            foreach ($list->getList() as $listItem) {

                if ($db->exists(
                    $config->getTable(),
                    $config->getShorthand(),
                    $listItem[$config->getJsonId()],
                )) {
                    $progressBar->advance();
                    continue;
                }

                sleep($sources->get()->getDelay());

                try {
                    $listItemData = MyAnimeListEntry::create($listItem[$config->getJsonId()]);
                } catch (\Exception $e) {
                    $console->io()->error("[".$listItem[$config->getJsonId()."] ".$e->getMessage()]);
                }

                $db->save(
                    $config->getTable(),
                    MyAnimeListBase::create($listItemData->getData())
                );
                $progressBar->advance();
            }

            $meta->incrementCurrentPage();
            sleep($sources->get()->getDelay());
        }

        $progressBar->finish();
    }
}