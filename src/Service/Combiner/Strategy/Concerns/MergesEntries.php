<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy\Concerns;

use Hiyori\Helper;
use Hiyori\Service\Database;
use Hiyori\Service\Exporter;
use Hiyori\Service\SourceConfigurationFactory;

/**
 * Shared "persist a merged record" helper used by the v2 combiner strategies.
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
     * Persist an already fully-accumulated model instance.
     */
    protected static function persistAccumulated(
        Database $db,
        SourceConfigurationFactory $source,
        object $merged,
        string $baseId,
        string $collection,
        float $score,
    ): bool {
        if ($baseId === '') {
            return false;
        }

        $baseShorthand = $source->get()->getShorthand();
        $refs = method_exists($merged, 'getReferenceIds') ? $merged->getReferenceIds() : [];
        $kind = $collection === 'organized_manga' ? 'manga' : 'anime';
        $slug = Exporter::slugify((string) $merged->getTitle());
        $extra = ['confidence' => $score];
        try {
            $extra['hiyori_id'] = $db->allocateHiyoriId($kind, is_array($refs) ? $refs : [], $slug);
        } catch (\Throwable) {
            // Pre-v3 databases have no id registry; keep the merge without it.
        }

        $db->mergeIntoOrganized($merged, $baseShorthand, $baseId, $collection, $extra);

        return true;
    }
}
