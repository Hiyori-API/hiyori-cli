<?php

declare(strict_types=1);

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
    public const NAME = 'metadata_fuzzy';

    /**
     * Default weights (sum does not need to be 100; they are normalized).
     * Supported fields: title, synonyms, type, episodes, status, season, year, tags.
     */
    private const DEFAULT_WEIGHTS = [
        'title' => 20,
        'synonyms' => 5,
        'type' => 15,
        'episodes' => 12,
        'status' => 12,
        'season' => 8,
        'year' => 10,
        'tags' => 18,
    ];

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): void {
        $weights = self::normalizeWeights($strategy->getConfig('weights') ?? self::DEFAULT_WEIGHTS);
        $threshold = self::normalizeThreshold($strategy->getConfig('threshold') ?? 70.0);

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

        $scores = [];
        $totalMerged = 0;
        $mergedSources = [];

        foreach ($cursor as $id => $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            $bestForEntry = 0.0;

            // Consider EVERY available source (do not break after the first hit)
            // so a single merged record accumulates references from all sources
            // that provide a confident match.
            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                $bestMatch = self::findBestMatch(
                    $db,
                    $currentSource->getTable(),
                    $entryArray,
                    $weights,
                    $threshold,
                );

                if ($bestMatch !== null) {
                    $score = (float) $bestMatch['_score'];
                    unset($bestMatch['_score']);
                    $scores[] = $score;
                    $bestForEntry = max($bestForEntry, $score);
                    $mergedSources[$currentSource->getTable()] = ($mergedSources[$currentSource->getTable()] ?? 0) + 1;

                    $db->mergeIntoOrganized(
                        AnimeBase::merge($entryArray, $bestMatch),
                        $source->get()->getShorthand(),
                        (string) ($entryArray['reference_ids'][$source->get()->getShorthand()] ?? ''),
                    );
                    $totalMerged++;
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Metadata Fuzzy merge complete. Total Merged: ' . $totalMerged . ' (threshold: ' . round($threshold, 1) . '%)');

        // Confidence summary
        if ($scores !== []) {
            $mean = array_sum($scores) / count($scores);
            $console->io()->text(sprintf(
                'Confidence: n=%d, min=%.1f%%, avg=%.1f%%, max=%.1f%%',
                count($scores),
                min($scores),
                $mean,
                max($scores),
            ));
            $low = count(array_filter($scores, fn ($s) => $s < 80.0));
            $console->io()->text(sprintf('Merges below 80%%: %d (%.1f%%)', $low, $low / count($scores) * 100));
            $bySource = [];
            foreach ($mergedSources as $src => $n) {
                $bySource[] = "$src=$n";
            }
            $console->io()->text('Merges by source: ' . implode(', ', $bySource));
        }
    }

    /**
     * Find the best matching entry in a target collection using weighted metadata comparison.
     * Returns the candidate array plus a synthetic '_score' key (0-100).
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

        // Progressive candidate narrowing: start strict, relax until candidates exist.
        $candidates = [];
        foreach (self::candidateFilterChain($entry) as $filter) {
            $cursor = $collection->find($filter, [
                'projection' => [
                    'title' => 1, 'synonyms' => 1, 'type' => 1, 'episodes' => 1,
                    'status' => 1, 'season' => 1, 'year' => 1, 'tags' => 1, '_id' => 0,
                ],
                'limit' => 100,
            ]);
            $candidates = [];
            foreach ($cursor as $candidate) {
                $candidates[] = Helper::convertMongoResultToArray($candidate);
            }
            if ($candidates !== []) {
                break;
            }
        }

        $bestScore = 0.0;
        $bestMatch = null;

        foreach ($candidates as $candidateArray) {
            $score = self::calculateScore($entry, $candidateArray, $weights);

            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestMatch = $candidateArray;
            }
        }

        if ($bestMatch !== null) {
            $bestMatch['_score'] = $bestScore;
        }

        return $bestMatch;
    }

    /**
     * Build a chain of candidate filters from strict to relaxed.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function candidateFilterChain(array $entry): array
    {
        $filters = [];

        // Strict: all three exact fields present
        $strict = [];
        foreach (['type', 'status', 'year'] as $field) {
            if (!empty($entry[$field])) {
                $strict[$field] = $entry[$field];
            }
        }
        if ($strict !== []) {
            $filters[] = $strict;

            // Relax by dropping one field at a time (year, status, type)
            foreach ([['year'], ['year', 'status'], ['year', 'status', 'type']] as $drop) {
                $relaxed = $strict;
                foreach ($drop as $field) {
                    unset($relaxed[$field]);
                }
                if ($relaxed !== []) {
                    $filters[] = $relaxed;
                }
            }
        }

        // Last resort: type only (or no filter)
        if (!empty($entry['type'])) {
            $filters[] = ['type' => $entry['type']];
        }
        $filters[] = [];

        return $filters;
    }

    /**
     * Weighted metadata similarity score (0-100).
     */
    public static function calculateScore(array $e1, array $e2, array $weights): float
    {
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($weights as $field => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $fieldScore = self::fieldSimilarity($e1, $e2, $field);
            // Normalize by total weight so result is on 0-100 scale.
            $score += ($fieldScore * $weight) / $totalWeight * 100;
        }

        return $score;
    }

    public static function fieldSimilarity(array $e1, array $e2, string $field): float
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
                return self::titleSimilarity((string) $v1, (string) ($e2['title'] ?? ''), $e1['synonyms'] ?? [], $e2['synonyms'] ?? []);
            case 'synonyms':
                return self::knownSimilarity((array) $v1);
            case 'tags':
                return self::tagsSimilarity((array) $v1, (array) $v2);
            case 'episodes':
            case 'year':
                return (int) $v1 === (int) $v2 ? 1.0 : 0.0;
            default:
                return strtolower(trim((string) $v1)) === strtolower(trim((string) $v2)) ? 1.0 : 0.0;
        }
    }

    /**
     * Title similarity: exact/normalized match, else synonym overlap, else token Jaccard.
     */
    public static function titleSimilarity(string $title1, string $title2, array $syn1, array $syn2): float
    {
        $t1 = self::normalizeTitle($title1);
        $t2 = self::normalizeTitle($title2);

        if ($t1 === '' && $t2 === '') {
            return 1.0;
        }
        if ($t1 === $t2) {
            return 1.0;
        }

        // Synonym overlap (either side contains the other's title)
        $allSyn1 = array_map([self::class, 'normalizeTitle'], array_merge([$title1], $syn1));
        $allSyn2 = array_map([self::class, 'normalizeTitle'], array_merge([$title2], $syn2));
        $inter = array_intersect($allSyn1, $allSyn2);
        if ($inter !== []) {
            return 0.9;
        }

        // Containment (one title is a substring of the other)
        if ($t1 !== '' && $t2 !== '' && (str_contains($t1, $t2) || str_contains($t2, $t1))) {
            return 0.75 + 0.15 * (min(strlen($t1), strlen($t2)) / max(strlen($t1), strlen($t2)));
        }

        // Token-set Jaccard
        return self::tokenJaccard($t1, $t2);
    }

    public static function tagsSimilarity(array $tags1, array $tags2): float
    {
        if ($tags1 === [] && $tags2 === []) {
            return 1.0;
        }
        if ($tags1 === [] || $tags2 === []) {
            return 0.0;
        }

        $set1 = array_flip(array_map('strtolower', $tags1));
        $set2 = array_flip(array_map('strtolower', $tags2));
        $intersection = count(array_intersect_key($set1, $set2));
        $union = count(array_merge($set1, $set2));

        /** @var int $union */
        return $union > 0 ? $intersection / $union : 0.0;
    }

    private static function normalizeWeights(array $weights): array
    {
        $known = array_fill_keys(array_keys(self::DEFAULT_WEIGHTS), 0);
        $weights = array_filter($weights, fn ($w) => is_numeric($w)) + $known;
        foreach ($weights as $field => $weight) {
            $weights[$field] = max(0.0, (float) $weight);
        }

        return $weights;
    }

    private static function normalizeThreshold(mixed $threshold): float
    {
        $threshold = (float) $threshold;
        if ($threshold <= 0 || $threshold > 100) {
            return 70.0;
        }

        return $threshold;
    }

    private static function normalizeTitle(string $title): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', $title) ?? ''));
    }

    private static function tokenJaccard(string $a, string $b): float
    {
        $tokensA = preg_split('/[\s_\-:()&\'"]+/', strtolower(trim($a)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokensB = preg_split('/[\s_\-:()&\'"]+/', strtolower(trim($b)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokensA === [] && $tokensB === []) {
            return 1.0;
        }
        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $setA = array_flip($tokensA);
        $setB = array_flip($tokensB);
        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        /** @var int $union */
        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Synonym field: returns 1 if the entry HAS synonyms at all (informational weight).
     */
    private static function knownSimilarity(array $synonyms): float
    {
        return $synonyms === [] ? 0.0 : 1.0;
    }
}
