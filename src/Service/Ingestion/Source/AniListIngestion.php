<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\AniListBase;
use Hiyori\Requests\AniList\AniListEntryList;
use Hiyori\Requests\AniList\AniListEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\Telemetry;

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
        $total = $meta->getTotalEntries();

        Telemetry::track($sourceKey, 0, $total, 0, 'running');

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        $processed = 0;
        $saved = 0;
        $errors = 0;
        $skipped = 0;

        while ($meta->hasNextPage()) {
            Telemetry::waitIfPaused($sourceKey);
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
                $processed++;

                if ($db->exists($config->getTable(), $config->getShorthand(), $itemId)) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                $rateLimiter->waitForToken($sourceKey, 1, 60);

                sleep($sources->get()->getDelay());

                $listItemData = null;
                try {
                    $listItemData = AniListBase::create($listItem);
                } catch (\Exception $e) {
                    $errors++;
                    $console->io()->error("[$itemId] " . $e->getMessage());
                }

                if ($listItemData !== null) {
                    $db->save($config->getTable(), $listItemData);
                    $saved++;
                }
                $progressBar->advance();

                if ($processed % 25 === 0) {
                    Telemetry::track($sourceKey, $processed, $total, $errors, 'running', 'initial', [
                        'saved' => $saved,
                        'last_checked_id' => $itemId,
                    ]);
                }
            }

            $meta->incrementCurrentPage();
        }

        $progressBar->finish();
        Telemetry::track($sourceKey, $processed, $total, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
        ]);
    }
}
