<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

/**
 * Minimal logistic-regression model loaded from a JSON file produced by
 * `ml:train` (or `services/ml/train_logreg.py`).
 *
 * File shape::
 *
 *   {
 *     "model": "logreg",
 *     "version": "logreg-2026.42.1",
 *     "features": ["exact_norm", "..."],
 *     "mean": [0.1, ...],
 *     "std":  [0.3, ...],
 *     "weights": [2.1, ...],
 *     "bias": -1.4,
 *     "trained_at": "2026-10-15T...Z",
 *     "metrics": {"n": 1234, "accuracy": 0.97}
 *   }
 */
final class LogisticModel
{
    /**
     * @param list<string> $features
     * @param list<float>  $mean
     * @param list<float>  $std
     * @param list<float>  $weights
     */
    private function __construct(
        private string $version,
        private array $features,
        private array $mean,
        private array $std,
        private array $weights,
        private float $bias,
        private array $metrics = [],
    ) {
    }

    public static function load(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['weights'] ?? null) || !is_array($data['features'] ?? null)) {
            return null;
        }

        $features = array_values(array_map('strval', $data['features']));
        $n = count($features);

        return new self(
            (string) ($data['version'] ?? 'unknown'),
            $features,
            self::floatList($data['mean'] ?? [], $n, 0.0),
            self::floatList($data['std'] ?? [], $n, 1.0),
            self::floatList($data['weights'], $n, 0.0),
            (float) ($data['bias'] ?? 0.0),
            is_array($data['metrics'] ?? null) ? $data['metrics'] : [],
        );
    }

    /**
     * @param array<string|int, float|int> $features assoc (name => value) or ordered list
     */
    public function predict(array $features): float
    {
        $z = $this->bias;
        foreach ($this->features as $i => $name) {
            $value = $features[$name] ?? $features[$i] ?? 0.0;
            $std = $this->std[$i] ?? 1.0;
            if ($std == 0.0) {
                $std = 1.0;
            }
            $z += (($value - ($this->mean[$i] ?? 0.0)) / $std) * ($this->weights[$i] ?? 0.0);
        }

        return 1.0 / (1.0 + exp(-$z));
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return list<string> */
    public function features(): array
    {
        return $this->features;
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * @param mixed $values
     *
     * @return list<float>
     */
    private static function floatList(mixed $values, int $length, float $default): array
    {
        $values = is_array($values) ? array_values($values) : [];
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $out[] = isset($values[$i]) && is_numeric($values[$i]) ? (float) $values[$i] : $default;
        }

        return $out;
    }
}
