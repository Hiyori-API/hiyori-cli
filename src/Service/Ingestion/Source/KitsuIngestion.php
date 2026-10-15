<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\KitsuBase;
use Hiyori\Requests\Kitsu\KitsuEntryDetail;
use Hiyori\Requests\Kitsu\KitsuEntryList;
use Hiyori\Requests\Kitsu\KitsuEntryListMeta;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\Telemetry;

final class KitsuIngestion
{
    public static function process(
        Database $db,
        ConsoleFactory $console,
        SourceConfigurationFactory $sources,
        RateLimiter $rateLimiter
    ): void {
        $meta = KitsuEntryListMeta::create()->setCurrentPage(0);
        $config = $sources->get();
        $sourceKey = 'kitsu';
        $total = $meta->getTotalEntries();
        Telemetry::track($sourceKey, 0, $total, 0, 'running');

        $processed = 0;
        $saved = 0;
        $errors = 0;
        $skipped = 0;

        $progressBar = $console->io()->createProgressBar($total);
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
                $itemId = (int) $listItem[$config->getJsonId()];
                $itemRef = (string) $listItem[$config->getJsonId()];
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
                    // One detail request embeds mappings, streaming links,
                    // genres and categories (previously four separate calls).
                    $detail = $rateLimiter->executeWithRetry(
                        fn () => KitsuEntryDetail::create($itemId, 'anime'),
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

                if ($processed % 25 === 0) {
                    Telemetry::track($sourceKey, $processed, $total, $errors, 'running', 'initial', [
                        'saved' => $saved,
                        'skipped' => $skipped,
                    ]);
                }
            }

            $meta->setCurrentPage($meta->getCurrentPage() + 20);
        }

        $progressBar->finish();
        Telemetry::track($sourceKey, $processed, $total, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
        ]);
    }
}
