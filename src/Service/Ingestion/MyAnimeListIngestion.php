<?php

namespace Hiyori\Service\Ingestion;

use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntry;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntryList;
use Hiyori\Sources\MyAnimeList\Requests\MyAnimeListEntryListMeta;

final class MyAnimeListIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void
    {
        $meta = MyAnimeListEntryListMeta::create();
        $config = $sources->get();

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT']);
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