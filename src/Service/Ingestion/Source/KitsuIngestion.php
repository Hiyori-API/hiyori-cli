<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\KitsuBase;
use Hiyori\Requests\Kitsu\KitsuEntryCategories;
use Hiyori\Requests\Kitsu\KitsuEntryGenres;
use Hiyori\Requests\Kitsu\KitsuEntryList;
use Hiyori\Requests\Kitsu\KitsuEntryListMeta;
use Hiyori\Requests\Kitsu\KitsuEntryMappings;
use Hiyori\Requests\Kitsu\KitsuEntryStreamingLinks;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;

final class KitsuIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources, RateLimiter $rateLimiter
    ): void {
        $meta = KitsuEntryListMeta::create()->setCurrentPage(0);
        $config = $sources->get();
        $sourceKey = 'kitsu';

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        while ($meta->getCurrentPage() <= $meta->getTotalEntries()) {
            $currentPage = $meta->getCurrentPage();

            $list = $rateLimiter->executeWithRetry(
                fn() => KitsuEntryList::create($currentPage),
                $sourceKey,
                3,
                1000,
                2.0
            );

            foreach ($list->getList() as $listItem) {
                $itemId = $listItem[$config->getJsonId()];

                if ($db->exists($config->getTable(), $config->getShorthand(), $itemId)) {
                    $progressBar->advance();
                    continue;
                }

                $rateLimiter->waitForToken($sourceKey, 1, 60);

                sleep($sources->get()->getDelay());

                $listItemData = null;
                try {
                    $mappings = $rateLimiter->executeWithRetry(
                        fn() => KitsuEntryMappings::create($itemId),
                        $sourceKey, 3, 1000, 2.0
                    );
                    $streamingLinks = $rateLimiter->executeWithRetry(
                        fn() => KitsuEntryStreamingLinks::create($itemId),
                        $sourceKey, 3, 1000, 2.0
                    );
                    sleep($sources->get()->getDelay());
                    $genres = $rateLimiter->executeWithRetry(
                        fn() => KitsuEntryGenres::create($itemId),
                        $sourceKey, 3, 1000, 2.0
                    );
                    $categories = $rateLimiter->executeWithRetry(
                        fn() => KitsuEntryCategories::create($itemId),
                        $sourceKey, 3, 1000, 2.0
                    );

                    $listItemData = array_merge($listItem,
                        ['streaming' => $streamingLinks->getData()],
                        ['external' => $mappings->getData()],
                        ['genres' => $genres->getData()],
                        ['categories' => $categories->getData()],
                    );

                } catch (\Exception $e) {
                    $console->io()->error("[$itemId] " . $e->getMessage());
                }

                if ($listItemData !== null) {
                    $db->save($config->getTable(), KitsuBase::create($listItemData));
                }
                $progressBar->advance();
            }

            $meta->setCurrentPage($meta->getCurrentPage() + 20);
        }

        $progressBar->finish();
    }
}
