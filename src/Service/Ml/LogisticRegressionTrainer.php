<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

/**
 * Dependency-free, deterministic logistic-regression trainer.
 *
 * Uses full-batch gradient descent with feature standardisation and balanced
 * class weights (candidate pairs are heavily imbalanced: most candidates are
 * negatives). Deterministic by design — no shuffling — so repeated runs on the
 * same JSONL produce identical models and tests stay stable.
 *
 * The rows must carry vectors of exactly the length of the feature set. A
 * shorter vector used to be padded with zeros, which trained a model that
 * believed two of its features were always absent — a silently useless
 * model. A row of the wrong length is now a hard error
 * (`FeatureSetMismatchException`).
 */
final class LogisticRegressionTrainer
{
    /**
     * @param list<array{vector: list<float>, label: int|float}> $rows
     * @param array{features?: list<string>, epochs?: int, learning_rate?: float, l2?: float, threshold?: float} $options
     *
     * @return array<string, mixed>
     */
    public function train(array $rows, array $options = []): array
    {
        $features = $options['features'] ?? FeatureExtractor::FEATURES;
        $epochs = max(1, (int) ($options['epochs'] ?? 400));
        $learningRate = (float) ($options['learning_rate'] ?? 0.5);
        $l2 = (float) ($options['l2'] ?? 0.001);
        $n = count($rows);
        if ($n === 0) {
            throw new \InvalidArgumentException('No training rows supplied.');
        }
        $d = count($features);

        $x = [];
        $y = [];
        foreach ($rows as $index => $row) {
            $vector = array_values($row['vector']);
            if (count($vector) !== $d) {
                throw FeatureSetMismatchException::staleTrainingRows(
                    (int) $index,
                    count($vector),
                    array_values($features),
                );
            }
            $point = [];
            for ($j = 0; $j < $d; $j++) {
                $point[] = (float) ($vector[$j] ?? 0.0);
            }
            $x[] = $point;
            $y[] = ((float) $row['label']) >= 0.5 ? 1.0 : 0.0;
        }

        $mean = array_fill(0, $d, 0.0);
        $std = array_fill(0, $d, 0.0);
        for ($j = 0; $j < $d; $j++) {
            $sum = 0.0;
            foreach ($x as $point) {
                $sum += $point[$j];
            }
            $mean[$j] = $sum / $n;
        }
        for ($j = 0; $j < $d; $j++) {
            $sum = 0.0;
            foreach ($x as $point) {
                $sum += ($point[$j] - $mean[$j]) ** 2;
            }
            $std[$j] = sqrt($sum / $n);
            if ($std[$j] < 1e-9) {
                $std[$j] = 1.0;
            }
        }

        $scaled = [];
        foreach ($x as $point) {
            $row = [];
            for ($j = 0; $j < $d; $j++) {
                $row[] = ($point[$j] - $mean[$j]) / $std[$j];
            }
            $scaled[] = $row;
        }

        $positives = array_sum($y);
        $negatives = $n - $positives;
        $weightPositive = $positives > 0 ? $n / (2 * $positives) : 0.0;
        $weightNegative = $negatives > 0 ? $n / (2 * $negatives) : 0.0;

        $weights = array_fill(0, $d, 0.0);
        $bias = 0.0;

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradW = array_fill(0, $d, 0.0);
            $gradB = 0.0;
            foreach ($scaled as $i => $point) {
                $z = $bias;
                for ($j = 0; $j < $d; $j++) {
                    $z += $weights[$j] * $point[$j];
                }
                $p = 1.0 / (1.0 + exp(-$z));
                $classWeight = $y[$i] > 0.5 ? $weightPositive : $weightNegative;
                $error = ($p - $y[$i]) * $classWeight;
                for ($j = 0; $j < $d; $j++) {
                    $gradW[$j] += $error * $point[$j];
                }
                $gradB += $error;
            }
            for ($j = 0; $j < $d; $j++) {
                $weights[$j] -= $learningRate * ($gradW[$j] / $n + $l2 * $weights[$j]);
            }
            $bias -= $learningRate * ($gradB / $n);
        }

        $scores = [];
        foreach ($scaled as $i => $point) {
            $z = $bias;
            for ($j = 0; $j < $d; $j++) {
                $z += $weights[$j] * $point[$j];
            }
            $scores[] = 1.0 / (1.0 + exp(-$z));
        }

        $threshold = (float) ($options['threshold'] ?? 0.5);

        return [
            'model' => 'logreg',
            'version' => 'logreg-' . gmdate('Y.m.d.His'),
            // The feature set is part of the model's identity: the weights are
            // positional, so a model can only be scored against the exact
            // feature list it was trained on. `LogisticModel` compares this
            // against the running code and refuses a mismatch.
            'feature_set_version' => self::featureSetVersion($features),
            'features' => array_values($features),
            'mean' => $mean,
            'std' => $std,
            'weights' => $weights,
            'bias' => $bias,
            'trained_at' => gmdate('c'),
            'metrics' => self::metrics($scores, $y, $threshold),
        ];
    }

    /**
     * The feature-set stamp written into a model file.
     *
     * The current feature set carries the version constant; a custom feature
     * list is stamped with its own fingerprint so it can never be mistaken
     * for the combiner's feature set.
     *
     * @param list<string> $features
     */
    private static function featureSetVersion(array $features): string
    {
        $features = array_values($features);
        if ($features === FeatureExtractor::FEATURES) {
            return FeatureExtractor::FEATURE_SET_VERSION;
        }

        return 'custom-' . substr(sha1(implode(',', $features)), 0, 12);
    }

    /**
     * @param list<float> $scores
     * @param list<float> $labels
     *
     * @return array<string, mixed>
     */
    public static function metrics(array $scores, array $labels, float $threshold = 0.5): array
    {
        $tp = $fp = $tn = $fn = 0;
        foreach ($scores as $i => $score) {
            $predicted = $score >= $threshold ? 1.0 : 0.0;
            $actual = $labels[$i] ?? 0.0;
            if ($predicted > 0.5 && $actual > 0.5) {
                $tp++;
            } elseif ($predicted > 0.5) {
                $fp++;
            } elseif ($actual > 0.5) {
                $fn++;
            } else {
                $tn++;
            }
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
        $accuracy = count($scores) > 0 ? ($tp + $tn) / count($scores) : 0.0;

        return [
            'n' => count($scores),
            'positives' => (int) array_sum($labels),
            'threshold' => $threshold,
            'tp' => $tp,
            'fp' => $fp,
            'tn' => $tn,
            'fn' => $fn,
            'accuracy' => round($accuracy, 4),
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1' => round($f1, 4),
            'auc' => round(self::auc($scores, $labels), 4),
        ];
    }

    /**
     * @param list<float> $scores
     * @param list<float> $labels
     */
    public static function auc(array $scores, array $labels): float
    {
        $positives = 0;
        $negatives = 0;
        $pairs = [];
        foreach ($scores as $i => $score) {
            $pairs[] = [$score, ($labels[$i] ?? 0.0) > 0.5];
            if (($labels[$i] ?? 0.0) > 0.5) {
                $positives++;
            } else {
                $negatives++;
            }
        }
        if ($positives === 0 || $negatives === 0) {
            return 0.5;
        }

        usort($pairs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $rankSum = 0.0;
        $rank = 1;
        foreach ($pairs as [$score, $isPositive]) {
            if ($isPositive) {
                $rankSum += $rank;
            }
            $rank++;
        }

        return ($rankSum - ($positives * ($positives + 1)) / 2) / ($positives * $negatives);
    }
}
