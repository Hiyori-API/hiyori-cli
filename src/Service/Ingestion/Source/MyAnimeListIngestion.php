<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Requests\MyAnimeList\MyAnimeListEntry;
use Hiyori\Requests\MyAnimeList\MyAnimeListEntryList;
use Hiyori\Requests\MyAnimeList\MyAnimeListEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\Ingestion\IngestionTraits;
use Hiyori\Service\SourceConfigurationFactory;

final class MyAnimeListIngestion
{
    use IngestionTraits;

    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void {
        $instance = new self();
        $instance->console = $console;
        $instance->run($db, $sources);
    }

    private ConsoleFactory $console;

    private function run(Database $db, SourceConfigurationFactory $sources): void
    {
        $meta = MyAnimeListEntryListMeta::create();
        $config = $sources->get();

        // Resumability: allow starting from a specific page
        $startPage = $config->getResumePage() ?? $this->loadProgress($config->getTable()) ?? 1;
        if ($startPage > 1) {
            $meta->setCurrentPage($startPage);
            $this->console->info("Resuming ingestion from page $startPage");
        }

        $progressBar = $this->console->createProgressBar($meta->getTotalEntries());
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        while ($meta->getCurrentPage() <= $meta->getLastPage()) {
            $currentPage = $meta->getCurrentPage();

            // Save progress for resumability
            $this->saveProgress($config->getTable(), $currentPage);

            $list = $this->withRetry(fn() => MyAnimeListEntryList::create($currentPage), 3, 2000);

            foreach ($list->getList() as $listItem) {
                $itemId = $listItem[$config->getJsonId()];
                
                $existing = $this->checkExists(
                    $db,
                    $sources,
                    $config->getTable(),
                    $config->getShorthand(),
                    $itemId,
                    true // fetch for update
                );

                if ($existing !== null && isset($existing['exists']) && !$config->isUpdate()) {
                    $progressBar->advance();
                    continue;
                }

                sleep($sources->get()->getDelay());

                $listItemData = $this->withRetry(
                    fn() => MyAnimeListEntry::create($itemId),
                    3,
                    2000
                );

                $entry = MyAnimeListBase::create($listItemData->getData());

                // Update existing or insert new
                $this->saveOrUpdate(
                    $db,
                    $sources,
                    $config->getTable(),
                    $entry,
                    $config->isUpdate() && $existing !== null
                );

                $progressBar->advance();
            }

            $meta->incrementCurrentPage();
            sleep($sources->get()->getDelay());
        }

        $progressBar->finish();
        $this->console->info("Ingestion complete for {$config->getName()}");
    }
}
