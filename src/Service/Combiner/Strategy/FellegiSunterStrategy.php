<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use Hiyori\Service\Telemetry;
use MongoDB\Collection;

/**
 * Fellegi–Sunter probabilistic record linkage.
 *
 * Each field contributes a weight based on (m, u) probabilities:
 *   agreement:    log2(m / u)
 *   disagreement: log2((1 - m) / (1 - u))
 * A per-field fuzzy similarity is treated as "agree" when it reaches
 * `agreement_cutoff` (default 0.85), which keeps the model explainable and
 * testable while still benefiting from fuzzy titles.
 *
 * The summed weight is scaled by the maximum achievable positive weight so
 * thresholds remain comparable to the 0-100 scale used elsewhere.
 */
final class FellegiSunterStrategy extends CombinerStrategy
{
    use MergesEntries;

    public const NAME = 'fellegi_sunter';

    /** Probability of agreement on a field given the records are a true pair. */
    public const DEFAULT_M = [
        'title' => 0.95,
        'synonyms' => 0.55,
        'type' => 0.98,
        'episodes' => 0.96,
        'status' => 0.90,
        'season' => 0.80,
        'year' => 0.92,
        'tags' => 0.55,
    ];

    /** Probability of agreement given the records are unrelated. */
    public const DEFAULT_U = [
        'title' => 0.02,
        'synonyms' => 0.10,
        'type' => 0.30,
        'episodes' => 0.05,
        'status' => 0.35,
        'season' => 0.25,
        'year' => 0.15,
        'tags' => 0.45,
    ];

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source,
    ): void {
        $m = self::normalizeProbabilities($strategy->getConfig('m') ?? self::DEFAULT_M, self::DEFAULT_M);
        $u = self::normalizeProbabilities($strategy->getConfig('u') ?? self::DEFAULT_U, self::DEFAULT_U);
        $cutoff = min(1.0, max(0.5, (float) ($strategy->getConfig('agreement_cutoff') ?? 0.85)));
        $threshold = (float) ($strategy->getConfig('threshold') ?? 60.0);
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');

        $tableName = $source->get()->getTable();
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $cursor = $baseCollection->find();
        $availableSources = $source->getSources();
        $baseShorthand = $source->get()->getShorthand();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat((string) ($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%'));
        $progressBar->start();

        $totalMerged = 0;
        $scores = [];
        $mergedSources = [];

        foreach ($cursor as $entry) {
            $entryArray = \Hiyori\Helper::convertMongoResultToArray($entry);

            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                $best = self::findBestMatch($db, $currentSource->getTable(), $entryArray, $m, $u, $cutoff, $threshold);
                if ($best === null) {
                    continue;
                }

                $score = (float) $best['_score'];
                unset($best['_score']);
                $scores[] = $score;
                $mergedSources[$currentSource->getTable()] = ($mergedSources[$currentSource->getTable()] ?? 0) + 1;

                if (self::persistMerge($db, $source, $entryArray, $best, $collection, $score)) {
                    $totalMerged++;
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Fellegi–Sunter merge complete. Merged: ' . $totalMerged);
        (new Telemetry())->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $totalMerged,
            'threshold' => $threshold,
            'agreement_cutoff' => $cutoff,
            'scores' => $scores !== [] ? [
                'avg' => round(array_sum($scores) / count($scores), 1),
                'min' => round(min($scores), 1),
                'max' => round(max($scores), 1),
            ] : null,
            'merged_sources' => $mergedSources,
            'completed_at' => date('c'),
        ]);
    }

    /**
     * @param array<string, float> $m
     * @param array<string, float> $u
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>|null
     */
    public static function findBestMatch(
        Database $db,
        string $targetTable,
        array $entry,
        array $m,
        array $u,
        float $cutoff,
        float $threshold,
    ): ?array {
        $bestScore = 0.0;
        $bestMatch = null;
        foreach (MetadataFuzzyStrategy::candidates($db, $targetTable, $entry) as $candidate) {
            $score = self::score($entry, $candidate, $m, $u, $cutoff);
            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        if ($bestMatch !== null) {
            $bestMatch['_score'] = $bestScore;
        }

        return $bestMatch;
    }

    /**
     * Fellegi–Sunter total weight scaled to 0-100.
     *
     * @param array<string, mixed> $e1
     * @param array<string, mixed> $e2
     * @param array<string, float> $m
     * @param array<string, float> $u
     */
    public static function score(array $e1, array $e2, array $m, array $u, float $cutoff): float
    {
        $total = 0.0;
        $max = 0.0;
        foreach ($m as $field => $mValue) {
            $uValue = $u[$field] ?? 0.5;
            $positive = log($mValue / max(1e-9, $uValue), 2);
            $max += max(0.0, $positive);

            $similarity = MetadataFuzzyStrategy::fieldSimilarity($e1, $e2, $field);
            $weight = $similarity >= $cutoff
                ? $positive
                : log((1 - $mValue) / max(1e-9, 1 - $uValue), 2);
            $total += $weight;
        }

        if ($max <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(100.0, ($total / $max) * 100));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, float> $defaults
     *
     * @return array<string, float>
     */
    private static function normalizeProbabilities(array $values, array $defaults): array
    {
        $out = [];
        foreach ($defaults as $field => $default) {
            $value = isset($values[$field]) && is_numeric($values[$field]) ? (float) $values[$field] : $default;
            $out[$field] = min(0.999, max(0.001, $value));
        }

        return $out;
    }
}
