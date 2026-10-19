<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner;

/**
 * The identity of a single combine run, and the marker it leaves behind.
 *
 * `combine` is upsert-only: it never deleted anything, so a document whose
 * base entry disappeared upstream — or lost the reference id it is keyed on —
 * stayed in the target collection and was published forever. A rebuild only
 * converged if the target was cleared by hand first.
 *
 * The fix is to mark what a run produced and delete what it did not, but the
 * target collection is shared: one `organized` document can carry `al`, `mal`
 * and `k` at the same time, and a different base may have written it. So the
 * marker is recorded *per base*:
 *
 *     _combine_build: { "al": "20260119T101112Z-4711-9f2c1ab4" }
 *
 * The key is the base's own reference shorthand and the value is the id of
 * the build that wrote the document. A sweep therefore only ever looks at the
 * entry that belongs to the base doing the sweeping — the documents of the
 * other bases are invisible to it (see {@see CombineSweeper}).
 *
 * The marker is written through the same `replaceOne` that writes the
 * document, so it can never drift from the document it describes.
 */
final class CombineBuild
{
    /**
     * The field the build marker lives in.
     *
     * Underscored because it is bookkeeping, not part of the work: the
     * exporter copies whole documents, so this field ends up in the exported
     * dataset unless the exporter is taught to skip it.
     */
    public const FIELD = '_combine_build';

    private function __construct()
    {
    }

    /**
     * A fresh, unique build id.
     *
     * Unique across concurrent runs (random suffix) and ordered by time (the
     * timestamp prefix), so a human reading a document can tell which run
     * wrote it and in which order.
     */
    public static function newId(): string
    {
        return \sprintf(
            '%s-%d-%s',
            gmdate('Ymd\THis\Z'),
            getmypid(),
            bin2hex(random_bytes(4)),
        );
    }

    /**
     * The fields to stamp on a document this build writes.
     *
     * The marker replaces the previous one: the document is written with a
     * `replaceOne`, so whatever the previous build recorded for it is gone
     * anyway. Recording "the last base that wrote me" is enough, because a
     * document that another base wrote is not this base's to delete.
     *
     * @return array<string, array<string, string>>
     */
    public static function stamp(string $baseShorthand, string $buildId): array
    {
        return [
            self::FIELD => [$baseShorthand => $buildId],
        ];
    }

    /**
     * The build id recorded for a base on a document, or '' when there is none.
     *
     * @param array<string, mixed> $document
     */
    public static function recordedFor(array $document, string $baseShorthand): string
    {
        $marker = $document[self::FIELD] ?? null;
        if (!is_array($marker)) {
            return '';
        }
        $id = $marker[$baseShorthand] ?? '';

        return is_string($id) ? $id : '';
    }
}
