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
    private const PER_PAGE = 50;

    public static function process(
        Database $db,
        ConsoleFactory $console,
        SourceConfigurationFactory $sources,
        RateLimiter $rateLimiter
    ): void {
        $config = $sources->get();
        $sourceKey = 'anilist';

        $meta = AniListEntryListMeta::create();
        $total = $meta->getTotalEntries() ?: 5000;

        $telemetry = new Telemetry();
        $telemetry->update('ingest-anilist', [
            'source' => $sourceKey, 'phase' => 'running', 'started_at_ts' => time(),
            'processed' => 0, 'errors' => 0, 'mode' => 'initial',
        ]);

        $processed = 0;
        $saved = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        $page = 1;
        while (true) {
            $list = $rateLimiter->executeWithRetry(
                fn () => AniListEntryList::create($page),
                $sourceKey,
                3,
                1000,
                2.0,
            );
            $items = $list->getList();

            if ($items === []) {
                break;
            }

            foreach ($items as $listItem) {
                $itemId = (int) $listItem[$config->getJsonId()];
                $processed++;

                if ($db->exists($config->getTable(), $config->getShorthand(), (string) $itemId)) {
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
            }

            // A short page signals the end of the pagination window.
            if (count($items) < self::PER_PAGE) {
                break;
            }

            $page++;
            if ($processed % 25 === 0) {
                Telemetry::track($sourceKey, $processed, $total, $errors, 'running', 'initial', [
                    'saved' => $saved,
                    'skipped' => $skipped,
                ], $telemetry->stateDir());
            }
        }
        $progressBar->finish();

        Telemetry::track($sourceKey, $processed, $total, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
        ], $telemetry->stateDir());
        $console->io()->info("AniList ingest done. Scanned: $processed, saved: $saved, skipped: $skipped, errors: $errors");
    }
}
