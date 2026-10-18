<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
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
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $cursor = $baseCollection->find();
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
            $covered = false;
            // Counted on the raw entry, before the merge path stamps the base
            // reference onto it (which is what would make it resolve).
            if (Helper::resolveReferenceId(Helper::convertMongoResultToArray($entry), $source->get()->getShorthand()) === '') {
                $unresolvedBaseId++;
            }
            foreach ($availableSources as $currentSource) {
                // skip if it's the same source as base source
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                $entryArray = Helper::convertMongoResultToArray($entry);
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

                            $db->mergeIntoOrganized(
                                $mergedEntry,
                                $baseShorthand,
                                $baseId,
                                $collection,
                                $extra,
                            );

                            $totalMerged++;
                            $covered = true;
                            break;
                        }
                    }
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
