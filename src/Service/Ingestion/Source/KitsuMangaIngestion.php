<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Manga\Base\KitsuBase;
use Hiyori\Requests\Kitsu\KitsuMangaEntryList as KitsuEntryList;
use Hiyori\Requests\Kitsu\KitsuMangaEntryListMeta as KitsuEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;

final class KitsuMangaIngestion
{
    public static function process(
        Database $db,
        ConsoleFactory $console,
        SourceConfigurationFactory $sources,
        RateLimiter $rateLimiter
    ): void {
        $meta = KitsuEntryListMeta::create()->setCurrentPage(0);
        $config = $sources->get();
        $sourceKey = 'kitsu_manga';

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        while ($meta->getCurrentPage() <= $meta->getTotalEntries()) {
            $currentPage = $meta->getCurrentPage();

            $list = $rateLimiter->executeWithRetry(
                fn () => KitsuEntryList::create($currentPage),
                $sourceKey,
                3,
                1000,
                2.0,
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
                        fn () => \Hiyori\Requests\Kitsu\KitsuEntryMappings::create($itemId),
                        $sourceKey,
                        3,
                        1000,
                        2.0,
                    );
                    $streamingLinks = $rateLimiter->executeWithRetry(
                        fn () => \Hiyori\Requests\Kitsu\KitsuEntryStreamingLinks::create($itemId),
                        $sourceKey,
                        3,
                        1000,
                        2.0,
                    );
                    sleep($sources->get()->getDelay());
                    $genres = $rateLimiter->executeWithRetry(
                        fn () => \Hiyori\Requests\Kitsu\KitsuEntryGenres::create($itemId),
                        $sourceKey,
                        3,
                        1000,
                        2.0,
                    );
                    $categories = $rateLimiter->executeWithRetry(
                        fn () => \Hiyori\Requests\Kitsu\KitsuEntryCategories::create($itemId),
                        $sourceKey,
                        3,
                        1000,
                        2.0,
                    );

                    $listItemData = array_merge(
                        $listItem,
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
