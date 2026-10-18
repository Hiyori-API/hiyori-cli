<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Manga\Base\AniListBase;
use Hiyori\Requests\AniList\AniListMangaEntryList as AniListEntryList;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\Telemetry;

final class AniListMangaIngestion
{
    private const PER_PAGE = 50;

    public static function process(
        Database $db,
        ConsoleFactory $console,
        SourceConfigurationFactory $sources,
        RateLimiter $rateLimiter
    ): void {
        $config = $sources->get();
        $sourceKey = 'anilist_manga';

        $telemetry = new Telemetry();
        $telemetry->update('ingest-anilist_manga', [
            'source' => $sourceKey, 'phase' => 'running', 'started_at_ts' => time(),
            'processed' => 0, 'errors' => 0, 'mode' => 'initial',
        ]);

        $processed = 0;
        $saved = 0;
        $skipped = 0;
        $errors = 0;

        // AniList caps pageInfo at 5000 per query; terminate each pagination pass on
        // short/empty pages rather than trusting the page-1 hasNextPage snapshot.
        $progressBar = $console->io()->createProgressBar(self::PER_PAGE * 100);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        // Manga have no seasonYear, so chunk by startDate year windows; AniList
        // pageInfo caps at 5000 per query, so each year window stays well under.
        $yearNow = (int) date('Y');
        $windows = [];
        for ($y = 1900; $y <= $yearNow; $y++) {
            $windows[] = [$y, $y + 1]; // startDate_greater: 0 is invalid for FuzzyDateInt
        }
        $windows[] = [null, null]; // catch-all for entries outside/without dates

        foreach ($windows as [$gt, $lt]) {
            $page = 1;
            $progressBar = $console->io()->createProgressBar(self::PER_PAGE * 100);
            $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
            $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
            $progressBar->start();
            $progressBar->setMessage(($gt === null ? 'n/a' : "$gt-$lt"), 'window');

            while (true) {
                $list = $rateLimiter->executeWithRetry(
                    fn () => AniListEntryList::create($page, $gt, $lt),
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
                    // Honour a pause set from the dashboard. Checked per record, not per
                    // page: a page is 500 entries and at the configured delay that can be
                    // many minutes, so pausing only between pages would read as a dead button.
                    Telemetry::waitIfPaused($sourceKey);
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

                if (count($items) < self::PER_PAGE) {
                    break;
                }

                $page++;
                if ($processed % 25 === 0) {
                    Telemetry::track($sourceKey, $processed, $processed, $errors, 'running', 'initial', [
                        'saved' => $saved,
                        'skipped' => $skipped,
                        'window' => $gt === null ? 'n/a' : "$gt-$lt",
                    ], $telemetry->stateDir());
                }
            }
            $progressBar->finish();
            $console->io()->info('Window ' . ($gt === null ? 'n/a' : "$gt-$lt") . ": saved $saved so far");
        }

        Telemetry::track($sourceKey, $processed, $processed, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
        ], $telemetry->stateDir());
        $console->io()->info("AniList Manga ingest done. Scanned: $processed, saved: $saved, skipped: $skipped, errors: $errors");
    }
}
