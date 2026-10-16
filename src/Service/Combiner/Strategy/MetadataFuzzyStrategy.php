<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Collection;

final class MetadataFuzzyStrategy extends CombinerStrategy
{
    use MergesEntries;

    public const NAME = 'metadata_fuzzy';

    /**
     * Minimum title similarity (0–1) required before a candidate may merge.
     *
     * Without this guard the weighted score can pass the threshold on
     * non-identifying fields alone: `type`+`status`+`episodes`+`season`+`year`
     * sum to 73.5, so two unrelated entries that share a year and episode count
     * score above 70 with **zero** title overlap (observed: "Sonic X Season 2"
     * merging with "Mai-Otome" and "Mushishi"). Set `title_floor` to 0 to
     * disable the guard.
     */
    public const DEFAULT_TITLE_FLOOR = 0.30;

    /**
     * Minimum length for a normalized title/synonym fragment to carry signal.
     *
     * `normalizeTitle()` strips everything outside `[a-z0-9]`, so a non-Latin
     * title can collapse to a stray Latin character: "殺せんせーQ！" and
     * "ポップQ" both normalize to "q". Treating such fragments as a synonym
     * hit (0.9) merged unrelated works. Fragments shorter than this are
     * ignored by the synonym/containment steps.
     */
    public const MIN_TITLE_FRAGMENT = 3;

    /**
     * Default weights derived from the weightage study (field match rates
     * measured on ground-truth pairs, e.g. myanimelist vs anilist):
     *   status 100%, episodes 99%, type 98%, title 74%, season 57%, year 57%, tags ~0%
     * Tags are intentionally near-zero: different sources use disjoint tag sets.
     * Sum does not need to be 100; weights are normalized at runtime.
     */
    public const DEFAULT_WEIGHTS = [
        // Optimized via `hiyori weightage` (discrimination-gap) on 600 matched
        // MAL<->AniList pairs; benchmark-validated (10,770 organized vs 6,873 fuzzy).
        'title' => 26.4,
        'synonyms' => 0,
        'type' => 5.7,
        'episodes' => 26.2,
        'status' => 0.6,
        'season' => 16.4,
        'year' => 23.2,
        'tags' => 1.5,
    ];

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): void {
        $weights = self::normalizeWeights($strategy->getConfig('weights') ?? self::DEFAULT_WEIGHTS);
        $threshold = self::normalizeThreshold($strategy->getConfig('threshold') ?? 70.0);
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');
        $anchorRejection = (bool) ($strategy->getConfig('anchor_rejection') ?? true);
        $anchorYearTolerance = (int) ($strategy->getConfig('anchor_year_tolerance') ?? 1);
        $anchorEpisodes = (bool) ($strategy->getConfig('anchor_episodes') ?? false);
        $titleFloor = (float) ($strategy->getConfig('title_floor') ?? self::DEFAULT_TITLE_FLOOR);
        $passthrough = (bool) ($strategy->getConfig('passthrough') ?? false);

        $tableName = $source->get()->getTable();
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $cursor = $baseCollection->find();
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat((string) ($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%'));
        $progressBar->start();

        $scores = [];
        $totalMerged = 0;
        $totalPassthrough = 0;
        $mergedSources = [];
        $candidatesExamined = 0;
        $rejectedAnchor = 0;
        $rejectedTitle = 0;
        $telemetry = new \Hiyori\Service\Telemetry();

        foreach ($cursor as $id => $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            $baseShorthand = $source->get()->getShorthand();
            // Entries without their own identifier (e.g. reference-less manga
            // rows) cannot key an organized document; they are skipped rather
            // than collapsed onto an empty upsert key.
            $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
            $bestForEntry = 0.0;

            // Consider EVERY available source (do not break after the first hit)
            // so a single merged record accumulates references from all sources
            // that provide a confident match.
            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                $match = self::match(
                    $entryArray,
                    self::candidates($db, $currentSource->getTable(), $entryArray),
                    $weights,
                    $threshold,
                    $anchorRejection,
                    $anchorYearTolerance,
                    $anchorEpisodes,
                    $titleFloor,
                );
                $candidatesExamined += $match['examined'];
                $rejectedAnchor += $match['rejected_anchor'];
                $rejectedTitle += $match['rejected_title'];
                $bestMatch = $match['best'];

                if ($bestMatch !== null) {
                    $score = (float) $bestMatch['_score'];
                    unset($bestMatch['_score']);
                    $scores[] = $score;
                    $bestForEntry = max($bestForEntry, $score);
                    $mergedSources[$currentSource->getTable()] = ($mergedSources[$currentSource->getTable()] ?? 0) + 1;

                    if ($baseId !== '') {
                        // Stamp the mangafied shorthand so the organized doc's
                        // upsert key matches on later merges (keeps dedup).
                        $entryArray['reference_ids'][$baseShorthand] = $baseId;

                        $mergedEntry = Helper::mergeEntries($tableName, $entryArray, $bestMatch);

                        // Ensure stable hiyori_id: reuse if refs already known, else allocate new.
                        $kind = self::targetKind($collection);
                        $slug = \Hiyori\Service\Exporter::slugify((string) $mergedEntry->getTitle());
                        $refs = $mergedEntry->getReferenceIds();
                        try {
                            $hiyoriId = $db->allocateHiyoriId($kind, $refs, $slug);
                            $extra = ['confidence' => $score, 'hiyori_id' => $hiyoriId];
                        } catch (\Throwable $e) {
                            // Fallback: continue without hiyori_id (should not happen after migration v3)
                            $extra = ['confidence' => $score];
                        }

                        $db->mergeIntoOrganized(
                            $mergedEntry,
                            $baseShorthand,
                            $baseId,
                            $collection,
                            $extra,
                        );
                        $totalMerged++;
                    }
                }
            }

            // Nothing matched: keep the entry itself (see persistPassthrough)
            // so the run cannot drop a base entry and orphan its registry id.
            if ($passthrough && $bestForEntry === 0.0 && $baseId !== '') {
                if (self::persistPassthrough($db, $source, $entryArray, $collection)) {
                    $totalPassthrough++;
                }
            }

            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Metadata Fuzzy merge complete. Total Merged: ' . $totalMerged . ' (threshold: ' . round($threshold, 1) . '%)');
        if ($passthrough) {
            $console->io()->text(sprintf(
                'Passthrough: %d single-source records kept (documents: %d)',
                $totalPassthrough,
                $totalMerged + $totalPassthrough,
            ));
        }

        // Confidence summary
        if ($scores !== []) {
            sort($scores);
            $n = count($scores);
            $mean = array_sum($scores) / $n;
            $median = $scores[intdiv($n, 2)];
            $g90 = count(array_filter($scores, fn ($s) => $s >= 90.0));
            $g85 = count(array_filter($scores, fn ($s) => $s >= 85.0));
            $g80 = count(array_filter($scores, fn ($s) => $s >= 80.0));
            $console->io()->text(sprintf(
                'Confidence: n=%d, min=%.1f%%, median=%.1f%%, avg=%.1f%%, max=%.1f%%',
                $n,
                $scores[0],
                $median,
                $mean,
                $scores[$n - 1],
            ));
            $console->io()->text(sprintf(
                'Score distribution: >=90%%: %d (%.1f%%), >=85%%: %d (%.1f%%), >=80%%: %d (%.1f%%)',
                $g90,
                $g90 / $n * 100,
                $g85,
                $g85 / $n * 100,
                $g80,
                $g80 / $n * 100,
            ));
            $low = count(array_filter($scores, fn ($s) => $s < 80.0));
            $console->io()->text(sprintf('Merges below 80%%: %d (%.1f%%)', $low, $low / $n * 100));
            $bySource = [];
            foreach ($mergedSources as $src => $n) {
                $bySource[] = "$src=$n";
            }
            $console->io()->text('Merges by source: ' . implode(', ', $bySource));
        }

        $telemetry->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $source->get()->getTable(),
            'merges' => $totalMerged,
            'passthrough' => $totalPassthrough,
            'documents' => $totalMerged + $totalPassthrough,
            'threshold' => round($threshold, 1),
            'candidates_examined' => $candidatesExamined,
            'rejected_anchor' => $rejectedAnchor,
            'rejected_title' => $rejectedTitle,
            'title_floor' => $titleFloor,
            'merged_sources' => $mergedSources,
            'anchor_rejection' => $anchorRejection,
            'anchor_year_tolerance' => $anchorYearTolerance,
            'scores' => $scores !== [] ? [
                'avg' => round(array_sum($scores) / count($scores), 1),
                'min' => round(min($scores), 1),
                'max' => round(max($scores), 1),
            ] : null,
            'score_histogram' => [
                'p70_80' => count(array_filter($scores, fn ($s) => $s >= 70 && $s < 80)),
                'p80_90' => count(array_filter($scores, fn ($s) => $s >= 80 && $s < 90)),
                'p90_100' => count(array_filter($scores, fn ($s) => $s >= 90)),
            ],
            'completed_at' => date('c'),
        ]);
        $telemetry->append('combine-events', [
            'strategy' => self::NAME,
            'base' => $source->get()->getTable(),
            'merges' => $totalMerged,
            'threshold' => round($threshold, 1),
            'candidates_examined' => $candidatesExamined,
            'rejected_anchor' => $rejectedAnchor,
            'rejected_title' => $rejectedTitle,
        ]);
    }

    /**
     * Find the best matching entry in a target collection using weighted metadata comparison.
     * Returns the candidate array plus a synthetic '_score' key (0-100).
     */
    public static function findBestMatch(
        Database $db,
        string $targetTable,
        array $entry,
        array $weights,
        float $threshold,
        bool $anchorRejection = true,
        int $anchorYearTolerance = 1,
        bool $anchorEpisodes = false
    ): ?array {
        return self::match(
            $entry,
            self::candidates($db, $targetTable, $entry),
            $weights,
            $threshold,
            $anchorRejection,
            $anchorYearTolerance,
            $anchorEpisodes,
        )['best'];
    }

    /**
     * Score a candidate pool with optional anchor-field rejection.
     *
     * Anchor rejection vetoes a candidate regardless of weighted score when
     * a hard field clearly disagrees: the base `type` family differs, or the
     * release years are further apart than `yearTolerance`. Episodes are only
     * used when explicitly enabled because split-cour releases legitimately
     * differ.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array<string, float>       $weights
     *
     * @return array{best: ?array<string, mixed>, examined: int, rejected_anchor: int, rejected_title: int}
     */
    public static function match(
        array $entry,
        array $candidates,
        array $weights,
        float $threshold,
        bool $anchorRejection = true,
        int $yearTolerance = 1,
        bool $rejectEpisodes = false,
        float $titleFloor = self::DEFAULT_TITLE_FLOOR
    ): array {
        $bestScore = 0.0;
        $bestMatch = null;
        $rejected = 0;
        $rejectedTitle = 0;

        foreach ($candidates as $candidateArray) {
            if ($anchorRejection && self::isAnchorRejected($entry, $candidateArray, $yearTolerance, $rejectEpisodes)) {
                $rejected++;

                continue;
            }

            if (self::isTitleRejected($entry, $candidateArray, $titleFloor)) {
                $rejectedTitle++;

                continue;
            }

            $score = self::calculateScore($entry, $candidateArray, $weights);
            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestMatch = $candidateArray;
            }
        }

        if ($bestMatch !== null) {
            $bestMatch['_score'] = $bestScore;
        }

        return [
            'best' => $bestMatch,
            'examined' => count($candidates),
            'rejected_anchor' => $rejected,
            'rejected_title' => $rejectedTitle,
        ];
    }

    /**
     * Title guard used by {@see match()}: reject a candidate whose title
     * similarity is below `$titleFloor` even if its weighted score passes.
     *
     * A floor of 0 disables the guard (legacy behaviour).
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $candidate
     */
    public static function isTitleRejected(array $entry, array $candidate, float $titleFloor): bool
    {
        if ($titleFloor <= 0.0) {
            return false;
        }

        $similarity = self::titleSimilarity(
            (string) ($entry['title'] ?? ''),
            (string) ($candidate['title'] ?? ''),
            (array) ($entry['synonyms'] ?? []),
            (array) ($candidate['synonyms'] ?? []),
        );

        return $similarity < $titleFloor;
    }

    /**
     * Hard anchor veto used by {@see match()}.
     *
     * @param array<string, mixed> $e1
     * @param array<string, mixed> $e2
     */
    public static function isAnchorRejected(
        array $e1,
        array $e2,
        int $yearTolerance = 1,
        bool $rejectEpisodes = false
    ): bool {
        $type1 = self::anchorType($e1['type'] ?? null);
        $type2 = self::anchorType($e2['type'] ?? null);
        if ($type1 !== '' && $type2 !== '' && $type1 !== $type2) {
            return true;
        }

        if ($yearTolerance >= 0) {
            $year1 = (int) ($e1['year'] ?? 0);
            $year2 = (int) ($e2['year'] ?? 0);
            if ($year1 > 0 && $year2 > 0 && abs($year1 - $year2) > $yearTolerance) {
                return true;
            }
        }

        if ($rejectEpisodes) {
            $ep1 = (int) ($e1['episodes'] ?? 0);
            $ep2 = (int) ($e2['episodes'] ?? 0);
            if ($ep1 > 0 && $ep2 > 0 && $ep1 !== $ep2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a media type to its family (`tv_short` → `tv`, `movie` → `movie`).
     */
    public static function anchorType(mixed $value): string
    {
        $type = strtolower(trim((string) $value));
        if ($type === '') {
            return '';
        }
        $type = preg_replace('/[^a-z0-9]+/', '_', $type) ?? $type;
        $parts = explode('_', trim($type, '_'));

        return $parts[0] ?? '';
    }

    /**
     * Return the narrowed candidate pool for an entry (highest-signal filter
     * that yields any rows, up to 100 rows).
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(Database $db, string $targetTable, array $entry, int $limit = 100): array
    {
        /** @var Collection $collection */
        $collection = $db->getCollection($targetTable);

        foreach (self::candidateFilterChain($entry) as $filter) {
            $cursor = $collection->find($filter, [
                'projection' => [
                    'title' => 1, 'synonyms' => 1, 'type' => 1, 'episodes' => 1,
                    'status' => 1, 'season' => 1, 'year' => 1, 'tags' => 1,
                    'references' => 1, 'reference_ids' => 1, '_id' => 0,
                ],
                'limit' => max(1, $limit),
            ]);
            $candidates = [];
            foreach ($cursor as $candidate) {
                $candidates[] = Helper::convertMongoResultToArray($candidate);
            }
            if ($candidates !== []) {
                return $candidates;
            }
        }

        return [];
    }

    /**
     * Build a chain of candidate filters from strict to relaxed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function candidateFilterChain(array $entry): array
    {
        $filters = [];

        // Strict: all three exact fields present
        $strict = [];
        foreach (['type', 'status', 'year'] as $field) {
            if (!empty($entry[$field])) {
                $strict[$field] = $entry[$field];
            }
        }
        if ($strict !== []) {
            $filters[] = $strict;

            // Relax by dropping one field at a time (year, status, type)
            foreach ([['year'], ['year', 'status'], ['year', 'status', 'type']] as $drop) {
                $relaxed = $strict;
                foreach ($drop as $field) {
                    unset($relaxed[$field]);
                }
                if ($relaxed !== []) {
                    $filters[] = $relaxed;
                }
            }
        }

        // Last resort: type only (or no filter)
        if (!empty($entry['type'])) {
            $filters[] = ['type' => $entry['type']];
        }
        $filters[] = [];

        return $filters;
    }

    /**
     * Weighted metadata similarity score (0-100).
     */
    public static function calculateScore(array $e1, array $e2, array $weights): float
    {
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($weights as $field => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $fieldScore = self::fieldSimilarity($e1, $e2, $field);
            // Normalize by total weight so result is on 0-100 scale.
            $score += ($fieldScore * $weight) / $totalWeight * 100;
        }

        return $score;
    }

    public static function fieldSimilarity(array $e1, array $e2, string $field): float
    {
        $v1 = $e1[$field] ?? null;
        $v2 = $e2[$field] ?? null;

        if ($v1 === null && $v2 === null) {
            return 1.0;
        }
        if ($v1 === null || $v2 === null) {
            return 0.0;
        }

        switch ($field) {
            case 'title':
                return self::titleSimilarity((string) $v1, (string) ($e2['title'] ?? ''), $e1['synonyms'] ?? [], $e2['synonyms'] ?? []);
            case 'synonyms':
                return self::knownSimilarity((array) $v1);
            case 'tags':
                return self::tagsSimilarity((array) $v1, (array) $v2);
            case 'episodes':
                return (int) $v1 === (int) $v2 ? 1.0 : 0.0;
            case 'year':
                return self::yearSimilarity($v1, $v2);
            default:
                $s1 = strtolower(trim((string) $v1));
                $s2 = strtolower(trim((string) $v2));
                if ($s1 === $s2) {
                    return 1.0;
                }

                // Fuzzy fallback for enum-ish fields (e.g. 'tv' vs 'tv_short')
                return max(self::jaroWinkler($s1, $s2), self::tokenJaccard($s1, $s2));
        }
    }

    /**
     * Title similarity: exact/normalized match, else synonym overlap, else token Jaccard.
     */
    /**
     * Normalized, non-empty titles for synonym comparison: the primary title
     * plus every string synonym, blanks removed.
     *
     * @param array<int, mixed> $synonyms
     *
     * @return list<string>
     */
    private static function nonEmptyNormalizedTitles(string $title, array $synonyms, int $minLength = 1): array
    {
        $titles = array_merge([$title], array_values(array_filter($synonyms, 'is_string')));
        $normalized = [];
        foreach ($titles as $candidate) {
            $value = self::normalizeTitle((string) $candidate);
            if ($value !== '' && mb_strlen($value) >= $minLength) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    public static function titleSimilarity(string $title1, string $title2, array $syn1, array $syn2): float
    {
        $t1 = self::normalizeTitle($title1);
        $t2 = self::normalizeTitle($title2);

        if ($t1 === '' && $t2 === '') {
            return 1.0;
        }
        // A one-character normalized title is virtually always a stripping
        // artefact of a non-Latin script; leave it to the Unicode comparison.
        if ($t1 === $t2 && mb_strlen($t1) >= 2) {
            return 1.0;
        }

        // Unicode-safe exact match (NFKC full-width folding, combining marks).
        $u1 = Helper::normalizeTitleUnicode($title1);
        $u2 = Helper::normalizeTitleUnicode($title2);
        if ($u1 !== '' && $u1 === $u2) {
            return 1.0;
        }

        // Synonym overlap (either side contains the other's title). Blank
        // entries are dropped first: sources routinely carry empty-string
        // synonyms, and `'' === ''` would otherwise make unrelated titles
        // look like a 0.9 synonym match.
        $allSyn1 = self::nonEmptyNormalizedTitles($title1, $syn1, self::MIN_TITLE_FRAGMENT);
        $allSyn2 = self::nonEmptyNormalizedTitles($title2, $syn2, self::MIN_TITLE_FRAGMENT);
        $inter = array_intersect($allSyn1, $allSyn2);
        if ($inter !== []) {
            return 0.9;
        }

        // Containment (one title is a substring of the other). Require enough
        // signal on both sides so short artefacts cannot contain each other.
        if (
            mb_strlen($t1) >= self::MIN_TITLE_FRAGMENT
            && mb_strlen($t2) >= self::MIN_TITLE_FRAGMENT
            && (str_contains($t1, $t2) || str_contains($t2, $t1))
        ) {
            return 0.75 + 0.15 * (min(strlen($t1), strlen($t2)) / max(strlen($t1), strlen($t2)));
        }

        // Token-set Jaccard
        return self::tokenJaccard($t1, $t2);
    }

    public static function tagsSimilarity(array $tags1, array $tags2): float
    {
        if ($tags1 === [] && $tags2 === []) {
            return 1.0;
        }
        if ($tags1 === [] || $tags2 === []) {
            return 0.0;
        }

        $set1 = array_flip(array_map('strtolower', $tags1));
        $set2 = array_flip(array_map('strtolower', $tags2));
        $intersection = count(array_intersect_key($set1, $set2));
        $union = count(array_merge($set1, $set2));

        /** @var int $union */
        return $union > 0 ? $intersection / $union : 0.0;
    }

    public static function normalizeWeights(array $weights): array
    {
        $known = array_fill_keys(array_keys(self::DEFAULT_WEIGHTS), 0);
        $weights = array_filter($weights, fn ($w) => is_numeric($w)) + $known;
        foreach ($weights as $field => $weight) {
            $weights[$field] = max(0.0, (float) $weight);
        }

        return $weights;
    }

    public static function normalizeThreshold(mixed $threshold): float
    {
        $threshold = (float) $threshold;
        if ($threshold <= 0 || $threshold > 100) {
            return 70.0;
        }

        return $threshold;
    }

    /**
     * Graded year similarity (dbsentinel-style): exact years score 1.0, each
     * year of difference removes 20% of the score (1 - |dy|/5, clamped to 0).
     */
    public static function yearSimilarity(mixed $y1, mixed $y2): float
    {
        $y1 = $y1 === null || $y1 === '' ? 0 : (int) $y1;
        $y2 = $y2 === null || $y2 === '' ? 0 : (int) $y2;

        if ($y1 === 0 || $y2 === 0) {
            return 0.0;
        }
        if ($y1 === $y2) {
            return 1.0;
        }

        return max(0.0, 1.0 - (abs($y1 - $y2) / 5.0));
    }

    /**
     * Jaro-Winkler similarity (0-1). Exposed for reuse by the weightage tool.
     */
    public static function jaroWinkler(string $a, string $b): float
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ($a === $b) {
            return 1.0;
        }

        $aLen = strlen($a);
        $bLen = strlen($b);
        if ($aLen === 0 || $bLen === 0) {
            return 0.0;
        }

        $matchDistance = intdiv(max($aLen, $bLen), 2) - 1;
        if ($matchDistance < 0) {
            $matchDistance = 0;
        }

        $aMatches = array_fill(0, $aLen, false);
        $bMatches = array_fill(0, $bLen, false);
        $matches = 0;

        for ($i = 0; $i < $aLen; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $bLen);
            for ($j = $start; $j < $end; $j++) {
                if ($bMatches[$j] || $a[$i] !== $b[$j]) {
                    continue;
                }
                $aMatches[$i] = true;
                $bMatches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $aLen; $i++) {
            if (!$aMatches[$i]) {
                continue;
            }
            while (!$bMatches[$k]) {
                $k++;
            }
            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $transpositions /= 2;

        $m = $matches;
        $jaro = (($m / $aLen) + ($m / $bLen) + (($m - $transpositions) / $m)) / 3;

        // Winkler boost for common prefix (up to 4 chars)
        $prefix = 0;
        $maxPrefix = min(4, $aLen, $bLen);
        for ($i = 0; $i < $maxPrefix; $i++) {
            if ($a[$i] === $b[$i]) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + ($prefix * 0.1 * (1.0 - $jaro));
    }

    private static function normalizeTitle(?string $title): string
    {
        if ($title === null) {
            return '';
        }

        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $title)));
    }

    public static function tokenJaccard(string $a, string $b): float
    {
        $tokensA = preg_split('/[\s_\-:()&\'"]+/', strtolower(trim($a)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokensB = preg_split('/[\s_\-:()&\'"]+/', strtolower(trim($b)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokensA === [] && $tokensB === []) {
            return 1.0;
        }
        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $setA = array_flip($tokensA);
        $setB = array_flip($tokensB);
        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        /** @var int $union */
        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Synonym field: returns 1 if the entry HAS synonyms at all (informational weight).
     */
    private static function knownSimilarity(array $synonyms): float
    {
        return $synonyms === [] ? 0.0 : 1.0;
    }
}
