<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\AniListBase;
use Hiyori\Requests\AniList\AniListEntryList;
use Hiyori\Requests\AniList\AniListEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;

final class AniListIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void
    {
        $meta = AniListEntryListMeta::create();
        $config = $sources->get();

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        while ($meta->hasNextPage()) {
            $currentPage = $meta->getCurrentPage();
            $list = AniListEntryList::create($currentPage);

            foreach ($list->getList() as $listItem) {

                if ($db->exists(
                    $config->getTable(),
                    $config->getShorthand(),
                    $listItem[$config->getJsonId()],
                )) {
                    $progressBar->advance();
                    continue;
                }

                try {
                    $listItemData = AniListBase::create($listItem);
                } catch (\Exception $e) {
                    $console->io()->error("[".$listItem[$config->getJsonId()."] ".$e->getMessage()]);
                }

                $db->save(
                    $config->getTable(),
                    $listItemData
                );

                $progressBar->advance();
            }

            $meta->incrementCurrentPage();
            sleep($sources->get()->getDelay());
        }

        $progressBar->finish();
    }
}