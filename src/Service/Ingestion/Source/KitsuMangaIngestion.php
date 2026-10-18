<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Manga\Base\KitsuBase;
use Hiyori\Requests\Kitsu\KitsuEntryDetail;
use Hiyori\Requests\Kitsu\KitsuMangaEntryList as KitsuEntryList;
use Hiyori\Requests\Kitsu\KitsuMangaEntryListMeta as KitsuEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\Telemetry;

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
        $total = $meta->getTotalEntries();

        $processed = 0;
        $saved = 0;
        $skipped = 0;
        $errors = 0;
        Telemetry::track($sourceKey, 0, $total, 0, 'running');

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        // Kitsu uses page[offset] pagination with a 20-item page — step offsets by 20.
        while ($meta->getCurrentPage() <= $total) {
            $currentPage = $meta->getCurrentPage();

            $list = $rateLimiter->executeWithRetry(
                fn () => KitsuEntryList::create($currentPage),
                $sourceKey,
                3,
                1000,
                2.0,
            );

            foreach ($list->getList() as $listItem) {
                $itemId = (int) $listItem[$config->getJsonId()];
                $itemRef = (string) $listItem[$config->getJsonId()];
                // Honour a pause set from the dashboard. Checked per record, not per
                // page: a page is 500 entries and at the configured delay that can be
                // many minutes, so pausing only between pages would read as a dead button.
                Telemetry::waitIfPaused($sourceKey);
                $processed++;

                if ($db->exists($config->getTable(), $config->getShorthand(), $itemRef)) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                $rateLimiter->waitForToken($sourceKey, 1, 60);
                sleep($sources->get()->getDelay());

                $listItemData = null;
                try {
                    // One detail request embeds mappings, genres and categories
                    // (manga has no streaming-links relationship).
                    $detail = $rateLimiter->executeWithRetry(
                        fn () => KitsuEntryDetail::create($itemId, 'manga'),
                        $sourceKey,
                        3,
                        1000,
                        2.0,
                    );

                    $listItemData = $detail->getData();
                } catch (\Exception $e) {
                    $errors++;
                    $console->io()->error("[$itemId] " . $e->getMessage());
                }

                if ($listItemData !== null) {
                    $db->save($config->getTable(), KitsuBase::create($listItemData));
                    $saved++;
                }
                $progressBar->advance();
            }

            if ($processed % 25 === 0) {
                Telemetry::track($sourceKey, $processed, $total, $errors, 'running', 'initial', [
                    'saved' => $saved,
                    'skipped' => $skipped,
                ]);
            }
            $meta->setCurrentPage($meta->getCurrentPage() + 20);
        }

        Telemetry::track($sourceKey, $processed, $total, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
        ]);
        $progressBar->finish();
        $console->io()->info("Kitsu Manga ingest complete: saved $saved, skipped $skipped, errors $errors");
    }
}
