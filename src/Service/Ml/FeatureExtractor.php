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
 */
final class FeatureExtractor
{
    /** Stable feature order; do not reorder without bumping the model version. */
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
    ];

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
}
