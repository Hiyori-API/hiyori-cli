<?php

namespace Hiyori\Service\Ingestion;

use Hiyori\Models\Anime\Base\KitsuBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryList;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryListMeta;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryMappings;
use Hiyori\Sources\Kitsu\Requests\KitsuEntryStreamingLinks;

final class KitsuIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void
    {
        $meta = KitsuEntryListMeta::create()
            ->setCurrentPage(0);
        $config = $sources->get();

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT']);
        $progressBar->start();

        while ($meta->getCurrentPage() <= $meta->getTotalEntries()) {
            $currentPage = $meta->getCurrentPage();
            $list = KitsuEntryList::create($currentPage);

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
                    $mappings = KitsuEntryMappings::create($listItem[$config->getJsonId()]);
                    $streamingLinks = KitsuEntryStreamingLinks::create($listItem[$config->getJsonId()]);

                    $listItemData = array_merge($listItem, ['streaming'=>$streamingLinks->getData()], ['external'=>$mappings->getData()]);

                } catch (\Exception $e) {
                    $console->io()->error("[".$listItem[$config->getJsonId()."] ".$e->getMessage()]);
                }

                $db->save(
                    $config->getTable(),
                    KitsuBase::create($listItemData)
                );
                $progressBar->advance();
            }

            $meta->setCurrentPage($meta->getCurrentPage()+20);
            sleep($sources->get()->getDelay());
        }

        $progressBar->finish();
    }
}