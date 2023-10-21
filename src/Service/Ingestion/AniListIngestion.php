<?php

namespace Hiyori\Service\Ingestion;

use Hiyori\Models\Anime\Base\AniListBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Sources\AniList\Requests\AniListEntryList;
use Hiyori\Sources\AniList\Requests\AniListEntryListMeta;

final class AniListIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void
    {
        $meta = AniListEntryListMeta::create();
        $config = $sources->get();

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT']);
        $progressBar->start();

        while ($meta->getCurrentPage() <= $meta->getTotalEntries()) {
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