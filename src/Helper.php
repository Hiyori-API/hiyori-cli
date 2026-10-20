<?php

namespace Hiyori;

use Hiyori\Models\Anime\Base\AnimeBase;
use Hiyori\Models\Common\Base;
use Hiyori\Models\Manga\Base\MangaBase;

class Helper
{
    public static function cleanse(string $string): string
    {
        return trim($string);
    }

    public static function prepareAsIdentifer(string $string): string
    {
        return strtolower(
            self::cleanse($string),
        );
    }

    /**
     * The key a title is compared and indexed under.
     *
     * Every non-alphanumeric character is removed and the rest is lower-cased,
     * so "Mata Mata Oboretai" and "Matamata O-bo-re-ta-i" are the same title.
     * The comparer and the stored key MUST use the same function, or the
     * candidate lookup will not find what the comparer would have matched.
     */
    public static function normalizeTitleKey(?string $title): string
    {
        if ($title === null) {
            return '';
        }

        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $title)));
    }

    public static function toArray(string $json): array
    {
        return json_decode($json, true);
    }

    /**
     * Canonical title normalisation shared by `metadata_fuzzy` and the graph- / ML-
     * based strategies. Normalises separators, case, romaji punctuation, and
     * collector-rendered episode counts so that titles that differ only in
     * punctuation/ordering still match.
     *
     * @return string lowercase, space-collapsed title.
     */
    public static function normalizeTitle(string $title, bool $stripEpisodeToken = true): string
    {
        $title = preg_replace("/ \(([^)]*)\) /u", ' \\1 ', $title) ?? $title;
        $title = str_replace([':', '_', '-', '/', chr(92), '!', '?', '.', '…', '・', chr(12463)], ' ', $title);
        $title = str_replace(' season ', ' ', $title);
        if ($stripEpisodeToken) {
            $title = preg_replace("/\b(ep|episode|ch)[p]?\s*\d+\b/iu", ' ', $title) ?? $title;
        }
        while (str_contains($title, '  ')) {
            $title = str_replace('  ', ' ', $title);
        }

        return trim(mb_strtolower($title, 'UTF-8'));
    }

    /**
     * SHA256 over the sorted "field->value" fingerprint of an entry. Used for
     * idempotent re-runs / content-change detection so ingestion and ML caches
     * don't recompute identical rows.
     *
     * @param array<string,mixed> $fields
     */
    public static function entryFingerprint(array $fields): string
    {
        ksort($fields);
        $fmt = [];
        foreach ($fields as $k => $v) {
            $fmt[] = $k . '=' . (is_array($v) ? implode(',', array_map('strval', $v)) : strval($v));
        }

        return hash('sha256', implode('|', $fmt));
    }

    /**
     * Resolve the identifier a source entry should be keyed by in the merged
     * (organized) collection. Looks up the configured shorthand first, then
     * falls back to the canonical reference key.
     *
     * Manga sources are ingested with their shareable identifier keys
     * ('al', 'k', 'mal' - parsed from source URLs), while the combine config
     * declares mangafied shorthands ('al_manga', 'k_manga', 'mal_manga') for
     * the merged collection. Without the fallback, manga entries would resolve
     * to '' and every merge would collapse onto a single garbage upsert key.
     *
     * @param array<string, mixed> $entryArray
     */
    public static function resolveReferenceId(array $entryArray, string $shorthand): string
    {
        $referenceIds = $entryArray['reference_ids'] ?? [];
        if (!is_array($referenceIds)) {
            return '';
        }

        if (isset($referenceIds[$shorthand])) {
            return (string) $referenceIds[$shorthand];
        }

        $mangaKey = preg_replace('/_manga$/', '', $shorthand);
        if (is_string($mangaKey) && $mangaKey !== $shorthand && isset($referenceIds[$mangaKey])) {
            return (string) $referenceIds[$mangaKey];
        }

        return '';
    }

    /**
     * Pick the merge model for a source table so manga merges keep manga
     * fields (chapters/volumes) instead of dropping them via the anime model,
     * then merge the two entries into that model.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $mergeEntry
     */
    public static function mergeEntries(string $table, array $entry, array $mergeEntry): Base
    {
        return str_ends_with($table, '_manga')
            ? MangaBase::merge($entry, $mergeEntry)
            : AnimeBase::merge($entry, $mergeEntry);
    }

    /**
     * Unicode-safe canonical title used for the exact-match shortcut in the
     * combiner. Applies NFKC (full-width folding, compatibility forms),
     * lowercases, drops combining marks and collapses whitespace. Purely
     * additive: callers keep their existing fuzzy path for non-exact matches.
     */
    public static function normalizeTitleUnicode(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($title, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $title = $normalized;
            }
        }

        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('/\p{Mn}+/u', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * Symfony's `%estimated%` placeholder needs a known maximum. Ingestion
     * sources without a reliable total (MAL ranking) pass 0 and need a format
     * without ETA, otherwise ProgressBar throws before the first advance.
     *
     * @param string|null $configured PROGRESSBAR_FORMAT from the environment
     */
    public static function progressFormat(?string $configured, int $total): string
    {
        $format = $configured !== null && $configured !== ''
            ? $configured
            : '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%';

        if ($total <= 0 && str_contains($format, '%estimated')) {
            $format = trim(str_replace(' | ETA:%estimated:-6s%', '', $format));
            if (str_contains($format, '%estimated')) {
                $format = '%current%/%max% [%bar%] %percent:3s%% MEM:%memory:6s%';
            }
        }

        return $format;
    }

    // https://stackoverflow.com/a/76375080
    public static function convertMongoResultToArray($result): array|string|int|float|bool|null
    {
        if (is_object($result)) {
            if ($result instanceof \MongoDB\Model\BSONDocument) {
                $result = $result->getArrayCopy();
            } elseif ($result instanceof \MongoDB\Model\BSONArray) {
                $result = iterator_to_array($result);
            } elseif ($result instanceof \MongoDB\BSON\ObjectId) {
                $result = (string) $result;
            }

            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    $result[$key] = self::convertMongoResultToArray($value);
                }
            }
        }

        return $result;
    }
}
