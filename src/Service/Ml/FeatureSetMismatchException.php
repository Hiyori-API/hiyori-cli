<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use RuntimeException;

/**
 * Raised when a stored ML model and the running code disagree about the
 * feature vector.
 *
 * The model file stores its weights as a positional vector plus the list of
 * feature names they belong to. If the runtime produces a different list
 * (added, removed, renamed or reordered features), every weight is applied
 * to the wrong feature and the resulting score is silently wrong — a wrong
 * merge is far more expensive than a failed run.
 *
 * So the mismatch is a hard error, never a warning:
 *
 *  - `LogisticModel::load()` refuses a model that `ml:train` produced against
 *    a different feature set (the combiner then aborts before it touches the
 *    database).
 *  - `LogisticModel::predict()` refuses a feature vector that does not line
 *    up with the model it is scoring against.
 *
 * The fix is always the same: re-export the pairs and retrain.
 */
final class FeatureSetMismatchException extends RuntimeException
{
    private const REMEDY = 'Retrain it: `php hiyori ml:export-pairs` followed by `php hiyori ml:train`.';

    /**
     * The stored model was trained against a different feature set.
     *
     * @param list<string> $modelFeatures   features the model was trained on
     * @param list<string> $currentFeatures features the running code produces
     */
    public static function staleModel(
        string $path,
        string $version,
        array $modelFeatures,
        array $currentFeatures,
    ): self {
        return new self(sprintf(
            'ML feature-set mismatch: the model at %s (version %s) was trained on %d features [%s], '
            . 'but this code produces %d features [%s]. The stored weights cannot be applied to this '
            . 'feature vector, so every score would be silently wrong. %s',
            $path,
            $version,
            count($modelFeatures),
            implode(', ', $modelFeatures),
            count($currentFeatures),
            implode(', ', $currentFeatures),
            self::REMEDY,
        ));
    }

    /**
     * A positional feature vector has the wrong number of values.
     *
     * @param list<string> $modelFeatures features the model was trained on
     */
    public static function vectorLengthMismatch(
        string $path,
        string $version,
        array $modelFeatures,
        int $givenCount,
    ): self {
        return new self(sprintf(
            'ML feature-set mismatch: the model at %s (version %s) was trained on %d features [%s], '
            . 'but the feature vector it was given holds %d values. The stored weights cannot be applied '
            . 'to this feature vector, so the score would be silently wrong. %s',
            $path,
            $version,
            count($modelFeatures),
            implode(', ', $modelFeatures),
            $givenCount,
            self::REMEDY,
        ));
    }

    /**
     * A training row carries a feature vector of the wrong length.
     *
     * @param list<string> $expectedFeatures the feature set being trained
     */
    public static function staleTrainingRows(
        int $rowIndex,
        int $givenCount,
        array $expectedFeatures,
    ): self {
        return new self(sprintf(
            'ML feature-set mismatch: training row %d holds %d feature values but the feature set has '
            . '%d features [%s]. The pairs file was exported against a different feature set, so the '
            . 'model would be trained on silently padded rows. Re-export the pairs with '
            . '`php hiyori ml:export-pairs` before training.',
            $rowIndex,
            $givenCount,
            count($expectedFeatures),
            implode(', ', $expectedFeatures),
        ));
    }

    /**
     * The feature vector handed to a model does not line up with it.
     *
     * @param list<string> $modelFeatures features the model was trained on
     * @param list<string> $givenFeatures features the caller supplied
     */
    public static function vectorMismatch(
        string $path,
        string $version,
        array $modelFeatures,
        array $givenFeatures,
    ): self {
        return new self(sprintf(
            'ML feature-set mismatch: the model at %s (version %s) was trained on %d features [%s], '
            . 'but the feature vector it was given holds %d features [%s]. The stored weights cannot be '
            . 'applied to this feature vector, so the score would be silently wrong. %s',
            $path,
            $version,
            count($modelFeatures),
            implode(', ', $modelFeatures),
            count($givenFeatures),
            implode(', ', $givenFeatures),
            self::REMEDY,
        ));
    }
}
