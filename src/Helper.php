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

    public static function toArray(string $json): array
    {
        return json_decode($json, true);
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
