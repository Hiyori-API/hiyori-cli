<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy\Concerns;

use Hiyori\Helper;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Database;

/**
 * Keyset pagination over a base collection, checkpointed per batch.
 *
 * The old shape held one server cursor open for the whole run, which a server
 * kills after ~10 minutes idle (the manga rebuild died with "cursor id ...
 * not found" at 45 %). This yields base entries in `_id`-ascending batches:
 * each batch is a short `_id > lastId` query that is fully drained into memory
 * before any of it is processed, so no cursor is ever left open across the
 * expensive per-entry work. After every batch the caller's counters and the
 * last committed `_id` are written to the checkpoint, so a crash loses at most
 * one batch.
 *
 * Strategies keep their own `foreach` body; only the iteration source changes:
 *
 *   foreach (self::baseEntryBatches($db, $table, $checkpoint, $counters) as $entryArray) {
 *       $counters['examined']++;
 *       ...
 *   }
 *
 * `$counters` is the single source of truth for progress (restored from the
 * checkpoint on resume) and is persisted verbatim after each batch.
 */
trait IteratesBaseBatches
{
    /**
     * Base rows pulled per query. Large enough that the per-batch checkpoint
     * write is negligible, small enough that a crash costs little work.
     */
    public const DEFAULT_BATCH_SIZE = 500;

    /**
     * @param array<string, mixed> $counters mutated by the caller, persisted each batch
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected static function baseEntryBatches(
        Database $db,
        string $baseTable,
        CombineCheckpoint $checkpoint,
        array &$counters,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): \Generator {
        $batchSize = max(1, $batchSize);
        $collection = $db->getCollection($baseTable);
        $lastId = $checkpoint->lastId();
        $completed = false;

        try {
            while (true) {
                $filter = $lastId === null ? [] : ['_id' => ['$gt' => $lastId]];

                // Drain the batch eagerly: the server cursor is closed before
                // the (slow) per-entry work starts, so it can never time out.
                $documents = $collection->find($filter, [
                    'sort' => ['_id' => 1],
                    'limit' => $batchSize,
                ])->toArray();

                if ($documents === []) {
                    break;
                }

                foreach ($documents as $document) {
                    $lastId = $document['_id'];
                    $entryArray = Helper::convertMongoResultToArray($document);
                    if (is_array($entryArray)) {
                        yield $entryArray;
                    }
                }

                $checkpoint->record($counters, $lastId);

                if (count($documents) < $batchSize) {
                    break;
                }
            }

            $completed = true;
        } finally {
            // The consumer throwing (or the process being stopped by a Mongo
            // error) unwinds through here. A `catch` cannot see a consumer's
            // exception — only `finally` runs — so mark the run failed unless
            // the base was actually exhausted. `last_id` stays at the last
            // committed batch, so `--resume` continues from there.
            if (!$completed) {
                $checkpoint->fail('combine stopped before the base was exhausted; resume with --resume');
            }
        }
    }

    /**
     * Fold one score into a resumable accumulator.
     *
     * Score summaries are persisted as aggregates rather than the raw list, so
     * a resumed run's `combine-latest` reports the whole run, not just the
     * portion processed after the resume.
     *
     * @param array<string, mixed> $counters
     */
    protected static function trackScore(array &$counters, string $prefix, float $score): void
    {
        $counters[$prefix . '_n'] = (int) ($counters[$prefix . '_n'] ?? 0) + 1;
        $counters[$prefix . '_sum'] = (float) ($counters[$prefix . '_sum'] ?? 0.0) + $score;
        $counters[$prefix . '_min'] = isset($counters[$prefix . '_min']) ? min((float) $counters[$prefix . '_min'], $score) : $score;
        $counters[$prefix . '_max'] = isset($counters[$prefix . '_max']) ? max((float) $counters[$prefix . '_max'], $score) : $score;
    }

    /**
     * @param array<string, mixed> $counters
     *
     * @return array{avg: float, min: float, max: float}|null
     */
    protected static function scoreSummary(array $counters, string $prefix, int $precision = 1): ?array
    {
        $n = (int) ($counters[$prefix . '_n'] ?? 0);
        if ($n <= 0) {
            return null;
        }

        return [
            'avg' => round((float) $counters[$prefix . '_sum'] / $n, $precision),
            'min' => round((float) $counters[$prefix . '_min'], $precision),
            'max' => round((float) $counters[$prefix . '_max'], $precision),
        ];
    }
}
