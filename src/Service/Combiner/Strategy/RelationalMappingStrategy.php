<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\AnimeBase;
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
            foreach ($availableSources as $currentSource) {
                // skip if it's the same source as base source
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                $sourceShorthand = $source->get()->getShorthand();
                if ($db->exists(
                    $currentSource->getTable(),
                    $currentSource->getShorthand(),
                    $entryArray['reference_ids'][$sourceShorthand] ?? '',
                )) {
                    $matchEntry = $db->get(
                        $currentSource->getTable(),
                        $currentSource->getShorthand(),
                        $entryArray['reference_ids'][$sourceShorthand] ?? '',
                        true,
                    );

                    $db->mergeIntoOrganized(
                        AnimeBase::merge(
                            $entryArray,
                            $matchEntry,
                        ),
                        $source->get()->getShorthand(),
                        (string) ($entryArray['reference_ids'][$source->get()->getShorthand()] ?? ''),
                    );

                    $totalMerged++;
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Merge complete. Total Merged: ' . $totalMerged);
    }
}
