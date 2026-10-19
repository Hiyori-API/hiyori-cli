<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\Combiner\CombineBuild;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Combiner\CombineStats;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Collection;

final class RelationalFuzzyStrategy extends CombinerStrategy
{
    public const NAME = 'relational_fuzzy';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): CombineStats {
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');
        $tableName = $source->get()->getTable();
        // Only match within the same kind (manga with manga, anime with anime).
        // Cross-kind candidates are vetoed by the type anchor anyway, so fetching
        // them is pure cost. Set `same_kind_candidates` to false to disable.
        $sameKindOnly = (bool) ($strategy->getConfig('same_kind_candidates') ?? true);
        $resume = (bool) ($strategy->getConfig('resume') ?? false);

        // Keyset pagination + a checkpoint per batch replaces the single
        // long-lived cursor that a server can kill mid-run.
        $checkpoint = CombineCheckpoint::begin(self::NAME, $tableName, $collection, $resume);
        // Every document this run writes carries the build id, so the sweep at
        // the end of the run can tell this run's documents from the stale ones
        // it did not write (see CombineSweeper).
        $buildId = $checkpoint->buildId();
        $counters = $checkpoint->counters([
            'skipped_cross_kind_sources' => 0,
            'examined' => 0,
            // `merged` is the coverage counter: base entries that produced a
            // document. `total_merged` counts writes, and one entry can write
            // once per matching source, so the two are not interchangeable.
            'merged' => 0,
            'passthrough' => 0,
            'unresolved_base_id' => 0,
            'total_merged' => 0,
        ]);

        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();
        if ((int) $counters['examined'] > 0) {
            $progressBar->setProgress(min((int) $counters['examined'], $total));
        }

        foreach (self::baseEntryBatches($db, $tableName, $checkpoint, $counters) as $rawEntry) {
            $counters['examined']++;
            $covered = false;
            // Counted on the raw entry, before the merge path stamps the base
            // reference onto it (which is what would make it resolve).
            if (Helper::resolveReferenceId($rawEntry, $source->get()->getShorthand()) === '') {
                $counters['unresolved_base_id']++;
            }
            foreach ($availableSources as $currentSource) {
                // skip if it's the same source as base source
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }
                if ($sameKindOnly && !MetadataFuzzyStrategy::sameKind($tableName, $currentSource->getTable())) {
                    $counters['skipped_cross_kind_sources'] = ($counters['skipped_cross_kind_sources'] ?? 0) + 1;
                    continue;
                }

                // A fresh copy per source, so the base reference stamped below
                // cannot leak into the next source's reference scan.
                $entryArray = $rawEntry;
                $baseShorthand = $source->get()->getShorthand();
                $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
                foreach ($entryArray['reference_ids'] ?? [] as $identifier => $identifierValue) {
                    if ($db->exists(
                        $currentSource->getTable(),
                        $identifier,
                        $identifierValue,
                    )) {

                        $matchEntry = $db->get(
                            $currentSource->getTable(),
                            $identifier,
                            $identifierValue,
                            true,
                        );

                        $matched = false;
                        foreach ($matchEntry['reference_ids'] ?? [] as $matchIdentifier => $matchIdentifierValue) {
                            // identifier exists on matched dataset entry
                            if ($matchIdentifier == $identifier && $identifierValue == $matchIdentifierValue) {
                                $matched = true;
                                break;
                            }
                        }

                        if ($matched && $baseId !== '') {
                            // Stamp the mangafied shorthand so the organized
                            // doc's upsert key matches on later merges.
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
                            $extra = array_merge($extra, CombineBuild::stamp($baseShorthand, $buildId));

                            $db->mergeIntoOrganized(
                                $mergedEntry,
                                $baseShorthand,
                                $baseId,
                                $collection,
                                $extra,
                            );

                            $counters['total_merged']++;
                            $covered = true;
                            break;
                        }
                    }
                }
            }
            if ($covered) {
                $counters['merged']++;
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Merge complete. Total Merged: ' . $counters['total_merged']);
        (new \Hiyori\Service\Telemetry())->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $source->get()->getTable(),
            'merges' => $counters['total_merged'],
            'completed_at' => date('c'),
        ]);
        $checkpoint->complete();

        return CombineStats::forRun(
            self::NAME,
            $tableName,
            (int) $counters['examined'],
            (int) $counters['merged'],
            (int) $counters['passthrough'],
            (int) $counters['unresolved_base_id'],
        );
    }
}
