<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
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
    ): void {
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
        foreach ($cursor as $id => $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            $baseShorthand = $source->get()->getShorthand();
            $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
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

                    $db->mergeIntoOrganized(
                        Helper::mergeEntries($tableName, $entryArray, $matchEntry),
                        $baseShorthand,
                        $baseId,
                        $collection,
                    );

                    $totalMerged++;
                }
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
    }
}
