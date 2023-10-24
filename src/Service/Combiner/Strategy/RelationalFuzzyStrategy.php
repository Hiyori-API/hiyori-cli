<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\AnimeBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;

final class RelationalFuzzyStrategy extends CombinerStrategy
{
    public const NAME = "relational_fuzzy";

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

                foreach ($entry->reference_ids as $identifier => $identifierValue) {
                    if ($db->exists(
                        $currentSource->getTable(),
                        $identifier,
                        $identifierValue
                    )) {

                        $matchEntry = $db->get(
                            $currentSource->getTable(),
                            $identifier,
                            $identifierValue,
                            true
                        );

                        $matched = false;
                        foreach ($matchEntry['reference_ids'] as $matchIdentifier => $matchIdentifierValue) {
                            // identifier exists on matched dataset entry
                            if ($matchIdentifier == $identifier && $identifierValue == $matchIdentifierValue) {
                                $matched = true;
                                break;
                            }
                        }

                        if ($matched) {
                            $db->mergeIntoOrganized(
                                AnimeBase::merge(
                                    Helper::convertMongoResultToArray($entry),
                                    $matchEntry
                                ),
                                $source->get()->getShorthand(),
                                $source->get()->getJsonId()
                            );

                            $totalMerged++;
                            break;
                        }
                    }
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info("Merge complete. Total Merged: ". $totalMerged);
    }
}