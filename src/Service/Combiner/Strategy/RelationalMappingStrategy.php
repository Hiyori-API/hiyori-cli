<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\Combiner\CombineStats;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Collection;

final class RelationalMappingStrategy extends CombinerStrategy
{
    public const NAME = 'relational_mapping';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): CombineStats {
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');
        $tableName = $source->get()->getTable();
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        // A plain server cursor is killed after ~10 min idle, which is how the
        // manga rebuild died at 45 % with "cursor id ... not found". Keep it
        // alive explicitly: a full base takes hours.
        $cursor = $baseCollection->find([], ['noCursorTimeout' => true, 'batchSize' => 500]);
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        $totalMerged = 0;
        // Coverage counters (see CombineStats). `$totalMerged` counts writes,
        // and one entry can write once per matching source, so coverage needs
        // its own per-entry counter.
        $examined = 0;
        $coveredEntries = 0;
        $unresolvedBaseId = 0;
        foreach ($cursor as $id => $entry) {
            $examined++;
            $entryArray = Helper::convertMongoResultToArray($entry);
            $baseShorthand = $source->get()->getShorthand();
            $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
            if ($baseId === '') {
                $unresolvedBaseId++;
            }
            $covered = false;
            foreach ($availableSources as $currentSource) {
                // skip if it's the same source as base source
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                if ($baseId === '') {
                    continue;
                }

                if ($db->exists(
                    $currentSource->getTable(),
                    $currentSource->getShorthand(),
                    $baseId,
                )) {
                    $matchEntry = $db->get(
                        $currentSource->getTable(),
                        $currentSource->getShorthand(),
                        $baseId,
                        true,
                    );

                    // Stamp the mangafied shorthand so the organized doc's
                    // upsert key matches on later merges (keeps dedup).
                    $entryArray['reference_ids'][$baseShorthand] = $baseId;

                    $mergedEntry = Helper::mergeEntries($tableName, $entryArray, $matchEntry);

                    // Ensure stable hiyori_id: reuse if refs already known, else allocate new.
                    $kind = $collection === 'organized_manga' ? 'manga' : 'anime';
                    $slug = \Hiyori\Service\Exporter::slugify((string) $mergedEntry->getTitle());
                    $refs = $mergedEntry->getReferenceIds();
                    try {
                        $hiyoriId = $db->allocateHiyoriId($kind, $refs, $slug);
                        $extra = ['hiyori_id' => $hiyoriId];
                    } catch (\Throwable $e) {
                        // Fallback: continue without hiyori_id
                        $extra = [];
                    }

                    $db->mergeIntoOrganized(
                        $mergedEntry,
                        $baseShorthand,
                        $baseId,
                        $collection,
                        $extra,
                    );

                    $totalMerged++;
                    $covered = true;
                }
            }
            if ($covered) {
                $coveredEntries++;
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Merge complete. Total Merged: ' . $totalMerged);
        (new \Hiyori\Service\Telemetry())->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $source->get()->getTable(),
            'merges' => $totalMerged,
            'completed_at' => date('c'),
        ]);

        return CombineStats::forRun(
            self::NAME,
            $tableName,
            $examined,
            $coveredEntries,
            0,
            $unresolvedBaseId,
        );
    }
}
