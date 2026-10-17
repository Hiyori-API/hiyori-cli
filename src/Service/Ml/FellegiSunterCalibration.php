<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Service\Combiner\Strategy\FellegiSunterStrategy;

/**
 * Calibrates the Fellegi–Sunter (m, u) field probabilities from labelled pairs.
 *
 * The strategy ships hand-set defaults; this fits them to the data:
 *
 *   m[f] = P(field f agrees | the pair IS the same work)
 *   u[f] = P(field f agrees | the pair is NOT the same work)
 *
 * A pair "agrees" on a field when that field's exported feature reaches the
 * strategy's `agreement_cutoff` (default 0.85), so the calibration matches the
 * scorer it is meant to feed — the two are calibrated on the same definition of
 * agreement.
 *
 * Laplace smoothing (`ALPHA`) keeps a field that never disagrees out of the
 * 0/1 extremes, and the result is clamped so m > u: a field that is *not*
 * more likely to agree for a true pair carries no positive evidence and would
 * otherwise subtract from the score.
 */
final class FellegiSunterCalibration
{
    /** Laplace prior added to each agreement count. */
    public const ALPHA = 1.0;

    /** Probabilities are clamped to this range to avoid log2(0) / log2(1). */
    public const MIN_P = 0.001;

    public const MAX_P = 0.999;

    /**
     * Exported feature -> Fellegi–Sunter field. Title folds the four lexical
     * similarities (any one reaching the cutoff counts as agreement).
     */
    public const FIELD_FEATURES = [
        'title' => ['jaro', 'token_jaccard', 'containment', 'exact_norm'],
        'synonyms' => ['synonym_overlap'],
        'type' => ['type_match'],
        'episodes' => ['episodes_match'],
        'status' => ['status_match'],
        'season' => ['season_match'],
        'year' => ['year_similarity'],
        'tags' => ['tags_jaccard'],
    ];

    /**
     * Fit m/u from a JSONL of labelled pairs (as produced by `ml:export-pairs`).
     *
     * @return array{m: array<string, float>, u: array<string, float>, counts: array<string, array{agree_pos: int, n_pos: int, agree_neg: int, n_neg: int}>}
     */
    public static function fit(string $jsonlPath, float $agreementCutoff = 0.85): array
    {
        $handle = @fopen($jsonlPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot read pairs file: %s', $jsonlPath));
        }

        // field => [agree_pos, n_pos, agree_neg, n_neg]
        $counts = [];
        foreach (array_keys(self::FIELD_FEATURES) as $field) {
            $counts[$field] = ['agree_pos' => 0, 'n_pos' => 0, 'agree_neg' => 0, 'n_neg' => 0];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $label = (int) ($row['label'] ?? -1);
            if ($label !== 0 && $label !== 1) {
                continue; // unlabeled / unsure rows teach nothing
            }
            $features = is_array($row['features'] ?? null) ? $row['features'] : [];
            if ($features === []) {
                continue;
            }

            foreach (self::FIELD_FEATURES as $field => $featureNames) {
                $agrees = false;
                foreach ($featureNames as $featureName) {
                    if (isset($features[$featureName]) && (float) $features[$featureName] >= $agreementCutoff) {
                        $agrees = true;
                        break;
                    }
                }
                $suffix = $label === 1 ? 'pos' : 'neg';
                $counts[$field]['n_' . $suffix]++;
                if ($agrees) {
                    $counts[$field]['agree_' . $suffix]++;
                }
            }
        }
        fclose($handle);

        $m = [];
        $u = [];
        foreach (self::FIELD_FEATURES as $field => $_) {
            $c = $counts[$field];
            $mP = ($c['agree_pos'] + self::ALPHA) / max(1, $c['n_pos'] + 2 * self::ALPHA);
            $uP = ($c['agree_neg'] + self::ALPHA) / max(1, $c['n_neg'] + 2 * self::ALPHA);
            $mP = self::clampProbability($mP);
            $uP = self::clampProbability($uP);
            // m must exceed u, else the field carries no positive evidence.
            if ($mP <= $uP) {
                $mP = min(self::MAX_P, $uP + (self::MAX_P - $uP) * 0.5);
            }
            $m[$field] = round($mP, 6);
            $u[$field] = round($uP, 6);
        }

        return ['m' => $m, 'u' => $u, 'counts' => $counts];
    }

    private static function clampProbability(float $p): float
    {
        return max(self::MIN_P, min(self::MAX_P, $p));
    }

    /**
     * The `--config` JSON that feeds a calibrated run back into the strategy.
     *
     * @param array{m: array<string, float>, u: array<string, float>} $fitted
     */
    public static function toStrategyConfig(array $fitted, float $agreementCutoff = 0.85): array
    {
        return [
            'm' => $fitted['m'],
            'u' => $fitted['u'],
            'agreement_cutoff' => $agreementCutoff,
        ];
    }

    /**
     * Load a previously saved calibration (from `combine:fs-calibrate`).
     *
     * @return array{m: array<string, float>, u: array<string, float>, agreement_cutoff: float}|null
     */
    public static function load(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['m'], $decoded['u'])) {
            return null;
        }
        if (!is_array($decoded['m']) || !is_array($decoded['u'])) {
            return null;
        }
        $m = self::numericMap($decoded['m'], FellegiSunterStrategy::DEFAULT_M);
        $u = self::numericMap($decoded['u'], FellegiSunterStrategy::DEFAULT_U);

        return [
            'm' => $m,
            'u' => $u,
            'agreement_cutoff' => isset($decoded['agreement_cutoff']) && is_numeric($decoded['agreement_cutoff'])
                ? (float) $decoded['agreement_cutoff']
                : 0.85,
        ];
    }

    /**
     * @param array<mixed>              $values
     * @param array<string, float>      $fallback
     *
     * @return array<string, float>
     */
    private static function numericMap(array $values, array $fallback): array
    {
        $out = [];
        foreach ($fallback as $field => $default) {
            $out[$field] = isset($values[$field]) && is_numeric($values[$field])
                ? (float) $values[$field]
                : $default;
        }

        return $out;
    }
}
