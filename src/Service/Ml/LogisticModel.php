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
 *     "feature_set_version": "2026.10.19-manga-counts",
 *     "features": ["exact_norm", "..."],
 *     "mean": [0.1, ...],
 *     "std":  [0.3, ...],
 *     "weights": [2.1, ...],
 *     "bias": -1.4,
 *     "trained_at": "2026-10-15T...Z",
 *     "metrics": {"n": 1234, "accuracy": 0.97}
 *   }
 *
 * Feature-set safety
 * ------------------
 * The weights are a positional vector: `weights[0]` belongs to
 * `features[0]`. The feature list is therefore part of the model's
 * identity, and a model whose feature list differs from the one the
 * running code produces cannot be scored at all — every weight would be
 * applied to the wrong feature and the resulting score would be silently
 * wrong.
 *
 * That is a hard error here, in two places:
 *
 *  - `load()` refuses a model that `ml:train` produced against a different
 *    feature set, so `combine --strategy=metadata_ml` aborts before it
 *    touches the database instead of merging on a broken score.
 *  - `predict()` refuses a feature vector that does not line up with the
 *    model it is scoring against.
 *
 * See `FeatureExtractor::FEATURE_SET_VERSION` and
 * `FeatureSetMismatchException`.
 */
final class LogisticModel
{
    /**
     * Version prefix written by `LogisticRegressionTrainer`. A model with
     * this prefix (or with an explicit `feature_set_version`) was produced
     * for the combiner and must therefore match the current feature set.
     */
    private const TRAINER_VERSION_PREFIX = 'logreg-';

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
        private ?string $path = null,
        private ?string $featureSetVersion = null,
    ) {
    }

    /**
     * @throws FeatureSetMismatchException when the stored model was trained
     *                                    against a different feature set
     */
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

        $model = new self(
            (string) ($data['version'] ?? 'unknown'),
            $features,
            self::floatList($data['mean'] ?? [], $n, 0.0),
            self::floatList($data['std'] ?? [], $n, 1.0),
            self::floatList($data['weights'], $n, 0.0),
            (float) ($data['bias'] ?? 0.0),
            is_array($data['metrics'] ?? null) ? $data['metrics'] : [],
            $path,
            isset($data['feature_set_version']) ? (string) $data['feature_set_version'] : null,
        );

        $model->guardAgainstStaleFeatureSet();

        return $model;
    }

    /**
     * @param array<string|int, float|int> $features assoc (name => value) or ordered list
     *
     * @throws FeatureSetMismatchException when the vector does not line up with the model
     */
    public function predict(array $features): float
    {
        $this->assertPredictionInput($features);

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

    /**
     * The feature set this model was trained against, when the model file
     * records it. `null` for models written before the field existed.
     */
    public function featureSetVersion(): ?string
    {
        return $this->featureSetVersion;
    }

    /** The file the model was loaded from, when known. */
    public function path(): ?string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * Whether the model was trained on exactly the given feature list.
     *
     * The comparison is on the whole list, order included: the weights are
     * positional, so a reordering is just as broken as a missing feature.
     *
     * @param list<string>|null $expected defaults to the current feature set
     */
    public function hasFeatureSet(?array $expected = null): bool
    {
        return $this->features === ($expected ?? FeatureExtractor::FEATURES);
    }

    /**
     * Explicit feature-set check. Throws when the model cannot be scored
     * against the given feature list.
     *
     * @param list<string>|null $expected defaults to the current feature set
     *
     * @throws FeatureSetMismatchException
     */
    public function assertFeatureSet(?array $expected = null): void
    {
        $expected ??= FeatureExtractor::FEATURES;
        if ($this->hasFeatureSet($expected)) {
            return;
        }

        throw FeatureSetMismatchException::staleModel(
            $this->path ?? '(in-memory model)',
            $this->version,
            $this->features,
            $expected,
        );
    }

    /**
     * Refuse a model that was trained for the combiner against a different
     * feature set.
     *
     * Only models that `ml:train` produced are checked: a hand-written
     * model file (a fixture, a one-off experiment) may legitimately carry a
     * different feature list, and `predict()` still refuses any vector that
     * does not line up with it.
     *
     * @throws FeatureSetMismatchException
     */
    private function guardAgainstStaleFeatureSet(): void
    {
        if (!$this->isTrainerProduced()) {
            return;
        }
        if ($this->featureSetVersion !== null && $this->featureSetVersion !== FeatureExtractor::FEATURE_SET_VERSION) {
            throw FeatureSetMismatchException::staleModel(
                $this->path ?? '(in-memory model)',
                $this->version,
                $this->features,
                FeatureExtractor::FEATURES,
            );
        }
        if ($this->hasFeatureSet()) {
            return;
        }

        throw FeatureSetMismatchException::staleModel(
            $this->path ?? '(in-memory model)',
            $this->version,
            $this->features,
            FeatureExtractor::FEATURES,
        );
    }

    /**
     * A model written by `ml:train` (or by the PHP trainer): its feature
     * list is the one the combiner will produce, so it must match.
     */
    private function isTrainerProduced(): bool
    {
        return $this->featureSetVersion !== null
            || str_starts_with($this->version, self::TRAINER_VERSION_PREFIX);
    }

    /**
     * The feature vector must be the model's own feature set: a positional
     * vector of the right length, or a map keyed by exactly the model's
     * features. Anything else means the caller and the model disagree
     * about what the columns are, and the score would be silently wrong.
     *
     * @param array<string|int, float|int> $features
     *
     * @throws FeatureSetMismatchException
     */
    private function assertPredictionInput(array $features): void
    {
        if (array_is_list($features)) {
            if (count($features) === count($this->features)) {
                return;
            }

            throw FeatureSetMismatchException::vectorLengthMismatch(
                $this->path ?? '(in-memory model)',
                $this->version,
                $this->features,
                count($features),
            );
        }

        $given = array_values(array_map('strval', array_keys($features)));
        sort($given);
        $expected = $this->features;
        sort($expected);
        if ($given === $expected) {
            return;
        }

        throw FeatureSetMismatchException::vectorMismatch(
            $this->path ?? '(in-memory model)',
            $this->version,
            $this->features,
            $given,
        );
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
