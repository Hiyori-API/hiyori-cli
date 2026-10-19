<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner;

use MongoDB\Collection;

/**
 * Reconcile a target collection with what a combine run actually produced.
 *
 * The combiner is upsert-only, so a document whose base entry disappeared
 * upstream used to stay in `organized` forever and be published as if it were
 * current. This class is the other half of the fix: after a successful run
 * it deletes the documents the run did *not* write.
 *
 * Two rules keep the deletion inside the base's own responsibility:
 *
 *  1. Only documents that carry the base's own reference key
 *     (`reference_ids.<shorthand>`) are candidates. A document whose identity
 *     is another base's key never matches, so a sweep can never touch it.
 *  2. A document that another base wrote is never a candidate either: the
 *     build marker is recorded per base, so a document another base wrote has
 *     no marker under this base's key and never matches.
 *
 * A document written before builds were stamped carries no marker at all. It
 * is treated as this base's to reconcile, because it carries the base's key
 * and nothing says another base owns it — that is what makes the first sweep
 * after the upgrade able to clear the debris of the old, never-deleting
 * combiner.
 */
final class CombineSweeper
{
    private function __construct()
    {
    }

    /**
     * Delete the documents in the target that this build did not produce.
     *
     * @return int the number of documents deleted
     */
    public static function sweep(Collection $target, string $baseShorthand, string $buildId): int
    {
        return $target->deleteMany(self::filter($baseShorthand, $buildId))->getDeletedCount();
    }

    /**
     * How many documents a sweep would delete.
     */
    public static function countStale(Collection $target, string $baseShorthand, string $buildId): int
    {
        return $target->countDocuments(self::filter($baseShorthand, $buildId));
    }

    /**
     * The delete filter.
     *
     * @return array<string, mixed>
     */
    public static function filter(string $baseShorthand, string $buildId): array
    {
        $own = CombineBuild::FIELD . '.' . $baseShorthand;

        return [
            // Rule 1: the base's own identity key. A document that only
            // carries another base's key does not match, so it is untouched.
            'reference_ids.' . $baseShorthand => ['$exists' => true],
            '$or' => [
                // A document from before builds were stamped: it carries the
                // base's key and no marker claims it for another base, so it
                // is the base's to reconcile.
                [CombineBuild::FIELD => ['$exists' => false]],
                // The base's own document, written by an older build. A
                // document another base wrote has no marker under this base's
                // key, so it never matches here — rule 2 falls out of the
                // marker being per base.
                [$own => ['$exists' => true, '$ne' => $buildId]],
            ],
        ];
    }
}
