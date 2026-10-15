<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

/**
 * Threshold-sweep evaluation for combiner scorers.
 *
 * Works on any scorer that produces a 0–100 score per candidate pair plus a
 * 0/1 label, so `metadata_fuzzy` (`metadata_score`) and `metadata_ml`
 * (probability × 100) share one harness and can be compared directly.
 */
final class BenchmarkEvaluator
{
    /**
     * @param list<float> $scores
     * @param list<int>   $labels
     * @param list<float> $thresholds
     *
     * @return list<array{threshold: float, tp: int, fp: int, tn: int, fn: int, precision: float, recall: float, f1: float, accuracy: float, predicted: int}>
     */
    public static function sweep(array $scores, array $labels, array $thresholds): array
    {
        $rows = [];
        foreach ($thresholds as $threshold) {
            $metrics = LogisticRegressionTrainer::metrics($scores, array_map('floatval', $labels), (float) $threshold);
            $rows[] = [
                'threshold' => (float) $threshold,
                'tp' => $metrics['tp'],
                'fp' => $metrics['fp'],
                'tn' => $metrics['tn'],
                'fn' => $metrics['fn'],
                'predicted' => $metrics['tp'] + $metrics['fp'],
                'precision' => $metrics['precision'],
                'recall' => $metrics['recall'],
                'f1' => $metrics['f1'],
                'accuracy' => $metrics['accuracy'],
            ];
        }

        return $rows;
    }

    /**
     * Deterministically assign a grouping key to `train` or `holdout`.
     *
     * The key MUST identify a whole group (e.g. `base_source:base_id`), not a
     * single row, so all rows for one base entry land on the same side and the
     * holdout is not leaked into training. Hash-based, so repeated exports of
     * the same data produce the same split.
     */
    public static function split(string $key, int $holdoutPercent): string
    {
        if ($holdoutPercent <= 0) {
            return 'train';
        }
        if ($holdoutPercent >= 100) {
            return 'holdout';
        }

        return (hexdec(substr(sha1($key), 0, 8)) % 100) < $holdoutPercent ? 'holdout' : 'train';
    }

    /**
     * @param list<array{threshold: float, f1: float, precision: float, recall: float}> $rows
     *
     * @return array{threshold: float, f1: float, precision: float, recall: float}|null
     */
    public static function best(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($best === null || $row['f1'] > $best['f1'] || ($row['f1'] === $best['f1'] && $row['precision'] > $best['precision'])) {
                $best = $row;
            }
        }

        return $best;
    }
}
