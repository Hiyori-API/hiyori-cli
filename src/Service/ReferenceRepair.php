<?php

declare(strict_types=1);

namespace Hiyori\Service;

use Hiyori\Models\Common\Identifiers\Identifiers;

/**
 * Recompute a manga document's identity from the URLs it already stores.
 *
 * Manga entries were ingested while the identifier table only knew `/anime/`
 * shapes, so reference_ids was empty for every manga row. The models also fed
 * related works into the same list, so recomputing naively would re-import the
 * contamination: a related manga would overwrite the entry's own id, and a
 * related anime would claim `al` on a manga document.
 *
 * Two rules make the repair safe:
 *  - the entry's own URL is the first reference (verified across all three manga
 *    collections: 0 violations in 195,946 documents);
 *  - a different `*_manga` shorthand may be adopted (a genuine cross-source
 *    link), but never an anime shorthand, and never the same shorthand twice
 *    (that is a related work, not this entry).
 */
final class ReferenceRepair
{
    /**
     * @param iterable<mixed> $references
     *
     * @return array<string, string>
     */
    public static function identityFromReferences(iterable $references): array
    {
        // MongoDB returns embedded arrays as BSONArray, not a PHP array.
        $references = is_array($references) ? array_values($references) : iterator_to_array($references, false);
        if ($references === []) {
            return [];
        }

        [$identifier, $id] = Identifiers::parseId((string) $references[0]);
        if ($identifier === null || $id === null) {
            return [];
        }

        $ownShorthand = $identifier::SHORTHAND;
        if (!self::isMangaShorthand($ownShorthand)) {
            return [];
        }

        $ids = [$ownShorthand => (string) $id];

        foreach (array_slice($references, 1) as $url) {
            [$other, $otherId] = Identifiers::parseId((string) $url);
            if ($other === null || $otherId === null) {
                continue;
            }

            $otherShorthand = $other::SHORTHAND;
            if (!self::isMangaShorthand($otherShorthand)) {
                continue; // a related anime, or a non-manga site
            }
            if ($otherShorthand === $ownShorthand) {
                continue; // a related manga: a different work with its own id
            }
            if (!isset($ids[$otherShorthand])) {
                $ids[$otherShorthand] = (string) $otherId;
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $ids */
    public static function hasAnimeKey(array $ids): bool
    {
        foreach (array_keys($ids) as $key) {
            if (!self::isMangaShorthand((string) $key)) {
                return true;
            }
        }

        return false;
    }

    private static function isMangaShorthand(string $shorthand): bool
    {
        return str_ends_with($shorthand, '_manga');
    }
}
