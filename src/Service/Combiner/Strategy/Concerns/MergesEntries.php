<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy\Concerns;

use Hiyori\Helper;
use Hiyori\Service\Database;
use Hiyori\Service\Exporter;
use Hiyori\Service\SourceConfigurationFactory;

/**
 * Shared "persist a merged record" helper used by the newer combiner strategies.
 */
trait MergesEntries
{
    /**
     * @param array<string, mixed> $entryArray base entry (already converted from BSON)
     * @param array<string, mixed> $match      matched entry from another source
     */
    protected static function persistMerge(
        Database $db,
        SourceConfigurationFactory $source,
        array $entryArray,
        array $match,
        string $collection,
        float $score,
    ): bool {
        $baseTable = $source->get()->getTable();
        $baseShorthand = $source->get()->getShorthand();
        $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
        if ($baseId === '') {
            return false;
        }

        return self::persistAccumulated(
            $db,
            $source,
            Helper::mergeEntries($baseTable, $entryArray, $match),
            $baseId,
            $collection,
            $score,
        );
    }

    /**
     * Registry kind for a target collection.
     *
     * Any collection whose name says manga holds manga ids. The exact
     * `=== 'organized_manga'` test this replaces handed *anime* ids to a manga
     * staging or benchmark collection, which created registry rows of the wrong
     * kind (and `hyori-id verify` then rejects the document).
     */
    protected static function targetKind(string $collection): string
    {
        return str_contains($collection, 'manga') ? 'manga' : 'anime';
    }

    /**
     * Persist an already fully-accumulated model instance.
     *
     * `$score` is the merge confidence. Pass null for a record that is not a
     * merge (see {@see persistPassthrough()}): the `confidence` field is then
     * left off entirely, which keeps single-source records out of every
     * score-driven view (`/api/histogram`, `/api/sample`, the Quality page's
     * min-score filter) instead of parking them in the 0-10 bin as if the
     * merge had been scored and failed.
     */
    protected static function persistAccumulated(
        Database $db,
        SourceConfigurationFactory $source,
        object $merged,
        string $baseId,
        string $collection,
        ?float $score,
    ): bool {
        if ($baseId === '') {
            return false;
        }

        $baseShorthand = $source->get()->getShorthand();
        $refs = method_exists($merged, 'getReferenceIds') ? $merged->getReferenceIds() : [];
        $kind = self::targetKind($collection);
        $slug = Exporter::slugify((string) $merged->getTitle());
        $extra = $score === null ? [] : ['confidence' => $score];
        try {
            $extra['hiyori_id'] = $db->allocateHiyoriId($kind, is_array($refs) ? $refs : [], $slug);
        } catch (\Throwable) {
            // Pre-v3 databases have no id registry; keep the merge without it.
        }

        $db->mergeIntoOrganized($merged, $baseShorthand, $baseId, $collection, $extra);

        return true;
    }

    /**
     * Persist a base entry that matched nothing, as its own single-source record.
     *
     * The merge loop only writes a document when at least one candidate matched,
     * so an unmatched base entry silently disappears from the merged collection.
     * That is what makes a plain rebuild lose coverage: the registry row
     * allocated for that entry (by an earlier run that did persist it) is left
     * with no document and `hiyori-id verify` reports "registry id N has no
     * document". Keeping one document per base entry is what makes a rebuild
     * id-stable, which is why the release pipeline combines with `--passthrough`.
     *
     * A passthrough document carries the base entry's own `reference_ids` (a
     * single key) and **no** `confidence`, so consumers can tell a merge from a
     * record that had nothing to merge with.
     *
     * @param array<string, mixed> $entryArray base entry (already converted from BSON)
     */
    protected static function persistPassthrough(
        Database $db,
        SourceConfigurationFactory $source,
        array $entryArray,
        string $collection,
    ): bool {
        $baseShorthand = $source->get()->getShorthand();
        $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
        if ($baseId === '') {
            return false;
        }

        // Stamp the base ref so the upsert key is the entry's own identity
        // (same convention as the merge path, and how `bootstrap`/`combine`
        // dedupe on later runs).
        $entryArray['reference_ids'][$baseShorthand] = $baseId;

        return self::persistAccumulated(
            $db,
            $source,
            Helper::mergeEntries($source->get()->getTable(), $entryArray, $entryArray),
            $baseId,
            $collection,
            null,
        );
    }
}
