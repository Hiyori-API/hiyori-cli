<?php

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\AnimeBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Collection;

final class MetadataFuzzyStrategy extends CombinerStrategy
{
    public const NAME = "metadata_fuzzy";

    // Default weights (can be overridden via strategy config)
    private const DEFAULT_WEIGHTS = [
        'title' => 20,
        'type' => 20,
        'episodes' => 15,
        'status' => 15,
        'season' => 10,
        'year' => 10,
        'tags' => 10,
    ];

    public static function process(
        Database $db, ConsoleFactory $console, StrategyConfigurationFactory $strategy, SourceConfigurationFactory $source
    ): void
    {
        $weights = $strategy->getConfig('weights') ?? self::DEFAULT_WEIGHTS;
        $threshold = (float) ($strategy->getConfig('threshold') ?? 70.0);

        $tableName = $source->get()->getTable();
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $cursor = $baseCollection->find();
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        $totalMerged = 0;
        foreach ($cursor as $id => $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);

            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                // Find best match in current source
                $bestMatch = self::findBestMatch(
                    $db,
                    $currentSource->getTable(),
                    $entryArray,
                    $weights,
                    $threshold
                );

                if ($bestMatch) {
                    $db->mergeIntoOrganized(
                        AnimeBase::merge($entryArray, $bestMatch),
                        $source->get()->getShorthand(),
                        $source->get()->getJsonId()
                    );
                    $totalMerged++;
                    break; // One match per source entry
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info("Metadata Fuzzy merge complete. Total Merged: $totalMerged (threshold: $threshold%)");
    }

    /**
     * Find the best matching entry in target collection using weighted metadata comparison.
     */
    private static function findBestMatch(
        Database $db,
        string $targetTable,
        array $entry,
        array $weights,
        float $threshold
    ): ?array {
        /** @var Collection $collection */
        $collection = $db->getCollection($targetTable);
        
        // First, try to narrow candidates by exact fields (type, status, year)
        $filter = [];
        if (!empty($entry['type'])) $filter['type'] = $entry['type'];
        if (!empty($entry['status'])) $filter['status'] = $entry['status'];
        if (!empty($entry['year'])) $filter['year'] = $entry['year'];

        $candidates = $collection->find($filter, [
            'projection' => ['title' => 1, 'type' => 1, 'episodes' => 1, 'status' => 1, 'season' => 1, 'year' => 1, 'tags' => 1, '_id' => 0],
            'limit' => 100, // Reasonable candidate pool
        ]);

        $bestScore = 0.0;
        $bestMatch = null;

        foreach ($candidates as $candidate) {
            $candidateArray = Helper::convertMongoResultToArray($candidate);
            $score = self::calculateScore($entry, $candidateArray, $weights);

            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestMatch = $candidateArray;
            }
        }

        return $bestMatch;
    }

    private static function calculateScore(array $e1, array $e2, array $weights): float
    {
        $totalWeight = array_sum($weights);
        if ($totalWeight === 0) {
            return 0.0;
        }

        $score = 0.0;

        foreach ($weights as $field => $weight) {
            $fieldScore = self::fieldSimilarity($e1, $e2, $field);
            $score += ($fieldScore * $weight) / $totalWeight * 100;
        }

        return $score;
    }

    private static function fieldSimilarity(array $e1, array $e2, string $field): float
    {
        $v1 = $e1[$field] ?? null;
        $v2 = $e2[$field] ?? null;

        if ($v1 === null && $v2 === null) {
            return 1.0;
        }
        if ($v1 === null || $v2 === null) {
            return 0.0;
        }

        switch ($field) {
            case 'title':
                return self::stringSimilarity((string)$v1, (string)$v2);
            case 'tags':
                return self::tagsSimilarity((array)$v1, (array)$v2);
            case 'episodes':
            case 'year':
                return (int)$v1 === (int)$v2 ? 1.0 : 0.0;
            default:
                return strtolower(trim((string)$v1)) === strtolower(trim((string)$v2)) ? 1.0 : 0.0;
        }
    }

    private static function stringSimilarity(string $s1, string $s2): float
    {
        $s1 = strtolower(trim($s1));
        $s2 = strtolower(trim($s2));

        if ($s1 === $s2) return 1.0;

        // Levenshtein-based similarity
        $maxLen = max(strlen($s1), strlen($s2));
        if ($maxLen === 0) return 1.0;

        $distance = levenshtein($s1, $s2);
        return 1.0 - ($distance / $maxLen);
    }

    private static function tagsSimilarity(array $tags1, array $tags2): float
    {
        if (empty($tags1) && empty($tags2)) return 1.0;
        if (empty($tags1) || empty($tags2)) return 0.0;

        $set1 = array_flip(array_map('strtolower', $tags1));
        $set2 = array_flip(array_map('strtolower', $tags2));
        $intersection = count(array_intersect_key($set1, $set2));
        $union = count(array_merge($set1, $set2));

        /** @var int $union */
        return $union > 0 ? $intersection / $union : 0.0;
    }
}
