<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\AnimeBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;

final class RelationalMappingStrategy extends CombinerStrategy
{
    public const NAME = "relational_mapping";

    public static function process(
        Database $db, ConsoleFactory $console, StrategyConfigurationFactory $strategy, SourceConfigurationFactory $source
    ): void
    {
        $tableName = $source->get()->getTable();
        $total = $db->getClient()->hiyori->{$tableName}->countDocuments();
        $cursor = $db->getClient()->hiyori->{$tableName}->find();
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        $totalMerged = 0;
        foreach ($cursor as $id => $entry) {
            foreach ($availableSources as $currentSource) {
                // skip if it's the same source as base source
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                if ($db->exists(
                    $currentSource->getTable(),
                    $currentSource->getShorthand(),
                    $entry->reference_ids->{$source->get()->getShorthand()}
                )) {
                    $matchEntry = $db->get(
                        $currentSource->getTable(),
                        $currentSource->getShorthand(),
                        $entry->reference_ids->{$source->get()->getShorthand()},
                        true
                    );

                    $db->mergeIntoOrganized(
                        AnimeBase::merge(
                            Helper::convertMongoResultToArray($entry),
                            $matchEntry
                        ),
                        $source->get()->getShorthand(),
                        $source->get()->getJsonId()
                    );

                    $totalMerged++;
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info("Merge complete. Total Merged: ". $totalMerged);
    }
}