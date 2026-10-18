<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\AniListBase;
use Hiyori\Requests\AniList\AniListEntryList;
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

        $telemetry = new Telemetry();
        $telemetry->update('ingest-anilist', [
            'source' => $sourceKey, 'phase' => 'running', 'started_at_ts' => time(),
            'processed' => 0, 'errors' => 0, 'mode' => 'initial',
        ]);

        $processed = 0;
        $saved = 0;
        $skipped = 0;
        $errors = 0;

        // AniList pageInfo caps at 5,000 records per paginated query. Chunk the
        // dataset by season year (each year is well under the cap) so ingestion
        // covers the full anime catalog, then one unfiltered pass for undated late
        // additions.
        $years = range(1950, (int) date('Y') + 1);
        $passes = $years;
        $passes[] = null;

        $lastYear = null;
        foreach ($passes as $year) {
            $lastYear = $year;
            $page = 1;
            $progressBar = $console->io()->createProgressBar(self::PER_PAGE * 100);
            $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
            $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
            $progressBar->start();
            $progressBar->setMessage((string) ($year ?? 'no-year'), 'year');

            while (true) {
                $list = $rateLimiter->executeWithRetry(
                    fn () => AniListEntryList::create($page, $year),
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
                        'year' => $year ?? 'n/a',
                    ], $telemetry->stateDir());
                }
            }
            $progressBar->finish();
            $console->io()->info('Year ' . ($year ?? 'n/a') . ": saved $saved, skipped $skipped (so far)");
        }

        Telemetry::track($sourceKey, $processed, $processed, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
            'last_year' => $lastYear,
        ], $telemetry->stateDir());
        $console->io()->info("AniList ingest done. Scanned: $processed, saved: $saved, skipped: $skipped, errors: $errors");
    }
}
