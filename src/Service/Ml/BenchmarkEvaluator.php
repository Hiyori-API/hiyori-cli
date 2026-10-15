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

    /**
     * Compare the ML scorer against the fuzzy baseline for the shipping gate.
     *
     * The gate passes when `metadata_ml` best F1 is at least the baseline F1
     * plus `requiredMargin` (in F1 points, 0–1 scale). A margin of 0 keeps the
     * original "must beat the baseline" semantics; the roadmap's shipping gate
     * uses +0.02 (2 points).
     *
     * @return array{comparable: bool, passed: bool, margin: float|null, required_margin: float, baseline_f1: float|null, ml_f1: float|null}
     */
    public static function gate(?float $baselineF1, ?float $mlF1, float $requiredMargin = 0.0): array
    {
        if ($baselineF1 === null || $mlF1 === null) {
            return [
                'comparable' => false,
                'passed' => false,
                'margin' => null,
                'required_margin' => $requiredMargin,
                'baseline_f1' => $baselineF1,
                'ml_f1' => $mlF1,
            ];
        }

        $margin = $mlF1 - $baselineF1;

        return [
            'comparable' => true,
            'passed' => $margin >= $requiredMargin,
            'margin' => round($margin, 4),
            'required_margin' => $requiredMargin,
            'baseline_f1' => round($baselineF1, 4),
            'ml_f1' => round($mlF1, 4),
        ];
    }
}
