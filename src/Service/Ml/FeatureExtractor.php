<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Helper;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;

/**
 * Turns a candidate pair (base entry, candidate entry) into a fixed-order
 * feature vector for the ML second-stage scorer / offline training.
 *
 * All features are in [0, 1] so a linear model stays interpretable and the
 * vector is directly comparable across sources. See
 * `docs/ml-augmentation-design.md` §4.1.
 *
 * Manga support
 * -------------
 * The first twelve features are the original anime-shaped set. For a manga
 * document `episodes` and `season` are always null, so `episodes_match` is
 * constantly 0.0 and `season_match` constantly 1.0: the model got no signal
 * at all from the fields manga actually have. The combiner's own scorer was
 * fixed for this the same week (`MetadataFuzzyStrategy::MANGA_WEIGHTS` now
 * scores `chapters` and `volumes` instead of `episodes` and `season`), so
 * the ML stage now carries the same two length features: `chapters_match`
 * and `volumes_match`.
 *
 * The two new features are APPENDED, never inserted: the index of every
 * pre-existing feature is unchanged, so an old model file still lines up
 * positionally instead of silently shifting every weight by one. That is
 * not a compatibility guarantee, it is a courtesy — the feature vector is
 * baked into the trained model (`storage/state/ml-model.json` stores
 * `mean`, `std`, `weights`, `bias` and the feature list), so ANY change to
 * this list invalidates the stored model. The mismatch is detected and
 * reported as a hard error by `LogisticModel`; see `FEATURE_SET_VERSION`
 * and `LogisticModel::assertFeatureSet()`.
 */
final class FeatureExtractor
{
    /**
     * Stable feature order; do not reorder without bumping the model version.
     *
     * Append only. Inserting or reordering a feature silently re-maps every
     * weight in a stored model, because the model file stores the weights as
     * a positional vector and the feature list is the only thing that says
     * which weight belongs to which feature.
     */
    public const FEATURES = [
        'exact_norm',
        'jaro',
        'token_jaccard',
        'containment',
        'synonym_overlap',
        'type_match',
        'episodes_match',
        'status_match',
        'season_match',
        'year_similarity',
        'tags_jaccard',
        'ref_overlap',
        // Manga length features (appended — see the class docblock).
        'chapters_match',
        'volumes_match',
    ];

    /**
     * Identifies the current feature set.
     *
     * Bump this whenever `FEATURES` changes (added, removed, renamed or
     * reordered). It is written into every model produced by
     * `LogisticRegressionTrainer` and compared against the running code by
     * `LogisticModel`, so a model trained against a different feature set is
     * a loud failure instead of a silently wrong score.
     */
    public const FEATURE_SET_VERSION = '2026.10.19-manga-counts';

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     *
     * @return array<string, float>
     */
    public function extract(array $base, array $candidate): array
    {
        $title1 = (string) ($base['title'] ?? '');
        $title2 = (string) ($candidate['title'] ?? '');

        $t1 = Helper::normalizeTitle($title1);
        $t2 = Helper::normalizeTitle($title2);
        $u1 = Helper::normalizeTitleUnicode($title1);
        $u2 = Helper::normalizeTitleUnicode($title2);

        $syn1 = array_values(array_filter((array) ($base['synonyms'] ?? []), 'is_string'));
        $syn2 = array_values(array_filter((array) ($candidate['synonyms'] ?? []), 'is_string'));

        $all1 = array_map([Helper::class, 'normalizeTitle'], array_merge([$title1], $syn1));
        $all2 = array_map([Helper::class, 'normalizeTitle'], array_merge([$title2], $syn2));
        $all1 = array_values(array_filter($all1, static fn (string $s): bool => $s !== ''));
        $all2 = array_values(array_filter($all2, static fn (string $s): bool => $s !== ''));

        return [
            'exact_norm' => ($u1 !== '' && $u1 === $u2) ? 1.0 : 0.0,
            'jaro' => MetadataFuzzyStrategy::jaroWinkler($t1, $t2),
            'token_jaccard' => MetadataFuzzyStrategy::tokenJaccard($t1, $t2),
            'containment' => self::containment($t1, $t2),
            'synonym_overlap' => array_intersect($all1, $all2) !== [] ? 1.0 : 0.0,
            'type_match' => MetadataFuzzyStrategy::fieldSimilarity($base, $candidate, 'type'),
            'episodes_match' => self::episodesMatch($base, $candidate),
            'status_match' => MetadataFuzzyStrategy::fieldSimilarity($base, $candidate, 'status'),
            'season_match' => MetadataFuzzyStrategy::fieldSimilarity($base, $candidate, 'season'),
            'year_similarity' => MetadataFuzzyStrategy::yearSimilarity($base['year'] ?? null, $candidate['year'] ?? null),
            'tags_jaccard' => MetadataFuzzyStrategy::tagsSimilarity(
                (array) ($base['tags'] ?? []),
                (array) ($candidate['tags'] ?? []),
            ),
            'ref_overlap' => self::sharesReference($base, $candidate) ? 1.0 : 0.0,
            'chapters_match' => self::chaptersMatch($base, $candidate),
            'volumes_match' => self::volumesMatch($base, $candidate),
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     *
     * @return list<float>
     */
    public function vector(array $base, array $candidate): array
    {
        $map = $this->extract($base, $candidate);
        $out = [];
        foreach (self::FEATURES as $feature) {
            $out[] = $map[$feature] ?? 0.0;
        }

        return $out;
    }

    /**
     * Any shared `reference_ids` key/value pair (same source id on both sides).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function sharesReference(array $a, array $b): bool
    {
        $refsA = is_array($a['reference_ids'] ?? null) ? $a['reference_ids'] : [];
        $refsB = is_array($b['reference_ids'] ?? null) ? $b['reference_ids'] : [];
        foreach ($refsA as $key => $value) {
            if (isset($refsB[$key]) && (string) $refsB[$key] === (string) $value) {
                return true;
            }
        }

        return false;
    }

    public static function containment(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        if (!str_contains($a, $b) && !str_contains($b, $a)) {
            return 0.0;
        }
        $min = min(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        $max = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));

        return $min / $max;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     */
    public static function episodesMatch(array $base, array $candidate): float
    {
        $a = (int) ($base['episodes'] ?? 0);
        $b = (int) ($candidate['episodes'] ?? 0);
        if ($a <= 0 || $b <= 0) {
            return 0.0;
        }

        return $a === $b ? 1.0 : 0.0;
    }

    /**
     * Manga chapter count agreement.
     *
     * Graded, exactly like the combiner's own manga scoring
     * (`MetadataFuzzyStrategy::countSimilarity()`): two sources rarely agree
     * on the last chapter of a series, so a near miss still scores highly.
     * A count of zero (or a missing field) means "unknown", not "zero
     * chapters", and therefore scores 0.0 — it is never a match.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     */
    public static function chaptersMatch(array $base, array $candidate): float
    {
        return self::countMatch($base, $candidate, 'chapters');
    }

    /**
     * Manga volume count agreement. Same rules as `chaptersMatch()`.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     */
    public static function volumesMatch(array $base, array $candidate): float
    {
        return self::countMatch($base, $candidate, 'volumes');
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     */
    private static function countMatch(array $base, array $candidate, string $field): float
    {
        return MetadataFuzzyStrategy::countSimilarity(
            (int) ($base[$field] ?? 0),
            (int) ($candidate[$field] ?? 0),
        );
    }
}
