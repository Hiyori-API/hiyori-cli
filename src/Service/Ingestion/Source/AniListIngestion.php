<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\AniListBase;
use Hiyori\Requests\AniList\AniListEntryList;
use Hiyori\Requests\AniList\AniListEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;

final class AniListIngestion
{
    public static function process(
        Database $db,
        ConsoleFactory $console,
        SourceConfigurationFactory $sources,
        RateLimiter $rateLimiter
    ): void {
        $meta = AniListEntryListMeta::create();
        $config = $sources->get();
        $sourceKey = 'anilist';

        $progressBar = $console->io()->createProgressBar($meta->getTotalEntries());
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        while ($meta->hasNextPage()) {
            $currentPage = $meta->getCurrentPage();

            $list = $rateLimiter->executeWithRetry(
                fn () => AniListEntryList::create($currentPage),
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
                    $listItemData = AniListBase::create($listItem);
                } catch (\Exception $e) {
                    $console->io()->error("[$itemId] " . $e->getMessage());
                }

                if ($listItemData !== null) {
                    $db->save($config->getTable(), $listItemData);
                }
                $progressBar->advance();
            }

            $meta->incrementCurrentPage();
        }

        $progressBar->finish();
    }
}
