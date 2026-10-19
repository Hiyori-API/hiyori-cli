<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner;

use Hiyori\Service\Telemetry;
use MongoDB\BSON\ObjectId;
use RuntimeException;

/**
 * Resumable progress for a `combine` run.
 *
 * The strategies used to iterate the base collection with one server cursor
 * that lived for the whole run. A crash (or a killed cursor, see
 * "cursor id ... not found") lost every bit of progress, and because telemetry
 * is only written at the very end the previous run's `combine-latest.json`
 * still looked current — the dashboard could not tell a dead run from a
 * finished one.
 *
 * This class keeps two files in `storage/state/`, using {@see Telemetry}'s
 * atomic, lock-serialised conventions:
 *
 *   combine-checkpoint.json — where the run stopped, plus its counters:
 *     {
 *       "stage": "running|done|failed",
 *       "base": "anilist_manga",
 *       "target_collection": "organized_manga",
 *       "strategy": "metadata_fuzzy",
 *       "last_id": "6acd3c16cb9cb7a099012952",
 *       "last_id_kind": "objectid",
 *       "examined": 5000,
 *       "merged": 1200,
 *       "passthrough": 900,
 *       "written": 2100,
 *       "unresolved_base_id": 3,
 *       "counters": { ...strategy-specific scalars... },
 *       "started_at": "...",
 *       "updated_at": "..."   // stamped by Telemetry
 *     }
 *
 *   combine-latest.json — the existing dashboard payload, plus:
 *     "phase": "running|done|failed" and a live "progress" snapshot.
 *
 * The `stage`/`phase` markers are written *before* the first batch, so a run
 * that dies mid-batch is distinguishable from one that finished, and
 * `--resume` can continue from `last_id` instead of starting over.
 */
final class CombineCheckpoint
{
    /** State file (without the `.json` Telemetry appends). */
    public const KEY = 'combine-checkpoint';

    /** The dashboard's combine payload; carries the `phase` marker. */
    public const LATEST_KEY = 'combine-latest';

    public const STAGE_RUNNING = 'running';

    public const STAGE_DONE = 'done';

    public const STAGE_FAILED = 'failed';

    /**
     * @param array<string, mixed> $state
     */
    private function __construct(
        private Telemetry $telemetry,
        private array $state,
    ) {
    }

    /**
     * The raw checkpoint currently on disk, or [] when there is none.
     *
     * @return array<string, mixed>
     */
    public static function peek(?Telemetry $telemetry = null): array
    {
        return ($telemetry ?? new Telemetry())->read(self::KEY);
    }

    /**
     * Open the checkpoint for a run.
     *
     * Without `$resume` this replaces whatever was stored: a full run starts
     * from zero, exactly as before checkpointing existed. With `$resume` the
     * stored counters and `last_id` are kept, but only after the stored run is
     * proven to be the same base, strategy and target.
     */
    public static function begin(
        string $strategy,
        string $base,
        string $targetCollection,
        bool $resume,
        ?Telemetry $telemetry = null,
    ): self {
        $telemetry ??= new Telemetry();

        if (!$resume) {
            $state = [
                'stage' => self::STAGE_RUNNING,
                'strategy' => $strategy,
                'base' => $base,
                'target_collection' => $targetCollection,
                'last_id' => null,
                'last_id_kind' => null,
                'examined' => 0,
                'merged' => 0,
                'passthrough' => 0,
                'written' => 0,
                'unresolved_base_id' => 0,
                'counters' => [],
                'started_at' => date('c'),
            ];
            $telemetry->update(self::KEY, $state);
        } else {
            $state = $telemetry->read(self::KEY);
            if ($state === []) {
                throw new RuntimeException(self::describeMissing());
            }
            $mismatch = self::mismatch($state, $strategy, $base, $targetCollection);
            if ($mismatch !== null) {
                throw new RuntimeException($mismatch);
            }
            if (($state['stage'] ?? '') === self::STAGE_DONE) {
                throw new RuntimeException(self::describeDone($state));
            }
            $state['stage'] = self::STAGE_RUNNING;
            $telemetry->update(self::KEY, ['stage' => self::STAGE_RUNNING]);
        }

        // Up-front marker: without it a crashed run is indistinguishable from
        // the last finished one, because `combine-latest` is only rewritten at
        // the very end.
        $telemetry->update(self::LATEST_KEY, [
            'phase' => self::STAGE_RUNNING,
            'strategy' => $strategy,
            'base' => $base,
        ]);

        return new self($telemetry, $state);
    }

    /**
     * Why the stored checkpoint cannot be resumed, or null when it can.
     *
     * @param array<string, mixed> $state
     */
    public static function mismatch(
        array $state,
        string $strategy,
        string $base,
        string $targetCollection,
    ): ?string {
        $checks = [
            'strategy' => $strategy,
            'base' => $base,
            'target_collection' => $targetCollection,
        ];
        foreach ($checks as $field => $expected) {
            $stored = (string) ($state[$field] ?? '');
            if ($stored !== $expected) {
                return \sprintf(
                    'combine --resume: the checkpoint belongs to a different run (%s is "%s", this run is "%s"); refusing to resume it.',
                    $field,
                    $stored,
                    $expected,
                );
            }
        }

        return null;
    }

    public static function describeMissing(): string
    {
        return \sprintf(
            'combine --resume: no checkpoint at storage/state/%s.json; run without --resume to start a fresh combine.',
            self::KEY,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function describeDone(array $state): string
    {
        $completedAt = (string) ($state['completed_at'] ?? '');

        return \sprintf(
            'combine --resume: the stored checkpoint is already done%s; there is nothing to resume. Run without --resume to start over.',
            $completedAt === '' ? '' : \sprintf(' (completed at %s)', $completedAt),
        );
    }

    /**
     * Restore the run counters over their defaults.
     *
     * Only keys present in `$defaults` are restored, so a checkpoint left by a
     * different strategy cannot leak its counters into this one.
     *
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    public function counters(array $defaults): array
    {
        $stored = $this->state['counters'] ?? [];
        if (!is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * The last `_id` a completed batch reached, as a value `find()` accepts, or
     * null when nothing has been committed yet.
     */
    public function lastId(): mixed
    {
        $kind = $this->state['last_id_kind'] ?? null;

        return self::decodeId(
            $this->state['last_id'] ?? null,
            is_string($kind) ? $kind : null,
        );
    }

    /**
     * Persist progress after a batch.
     *
     * @param array<string, mixed> $counters
     */
    public function record(array $counters, mixed $lastId): void
    {
        [$value, $kind] = self::encodeId($lastId);
        $examined = (int) ($counters['examined'] ?? 0);
        $merged = (int) ($counters['merged'] ?? 0);
        $passthrough = (int) ($counters['passthrough'] ?? 0);
        $unresolved = (int) ($counters['unresolved_base_id'] ?? 0);

        $update = [
            'stage' => self::STAGE_RUNNING,
            'last_id' => $value,
            'last_id_kind' => $kind,
            'examined' => $examined,
            'merged' => $merged,
            'passthrough' => $passthrough,
            // `written` follows the CombineStats contract; keep it in the file
            // so an operator reading the checkpoint sees coverage at a glance.
            'written' => $merged + $passthrough,
            'unresolved_base_id' => $unresolved,
            'counters' => $counters,
        ];

        $this->telemetry->update(self::KEY, $update);
        $this->state = array_merge($this->state, $update);

        // Refresh `updated_at` on every batch: the dashboard treats a `running`
        // phase whose file has not been touched in 90 s as stale, and a live
        // combine can sit on one batch for a while.
        $this->telemetry->update(self::LATEST_KEY, [
            'phase' => self::STAGE_RUNNING,
            'progress' => [
                'examined' => $examined,
                'merged' => $merged,
                'passthrough' => $passthrough,
                'written' => $merged + $passthrough,
                'last_id' => $value,
            ],
        ]);
    }

    /**
     * Mark the run finished, so a later `--resume` refuses instead of silently
     * skipping the base rows this run already covered.
     */
    public function complete(): void
    {
        $this->telemetry->update(self::KEY, [
            'stage' => self::STAGE_DONE,
            'completed_at' => date('c'),
        ]);
        $this->state['stage'] = self::STAGE_DONE;
        $this->telemetry->update(self::LATEST_KEY, ['phase' => self::STAGE_DONE]);
    }

    /**
     * Mark the run stopped (crash / error). `last_id` is left at the last
     * completed batch, so `--resume` continues from there.
     */
    public function fail(string $reason): void
    {
        $this->telemetry->update(self::KEY, [
            'stage' => self::STAGE_FAILED,
            'failed_at' => date('c'),
            'error' => $reason,
        ]);
        $this->state['stage'] = self::STAGE_FAILED;
        $this->telemetry->update(self::LATEST_KEY, ['phase' => self::STAGE_FAILED]);
    }

    /**
     * @return array{0: string|int|float|null, 1: string|null}
     */
    private static function encodeId(mixed $id): array
    {
        if ($id instanceof ObjectId) {
            return [(string) $id, 'objectid'];
        }
        if (is_int($id)) {
            return [$id, 'int'];
        }
        if (is_float($id)) {
            return [$id, 'float'];
        }
        if (is_string($id)) {
            return [$id, 'string'];
        }

        return [null, null];
    }

    private static function decodeId(mixed $value, ?string $kind): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($kind === 'objectid') {
            $id = (string) $value;
            if (preg_match('/^[a-f0-9]{24}$/i', $id) !== 1) {
                throw new RuntimeException(\sprintf(
                    'combine --resume: stored last_id "%s" is not a valid ObjectId.',
                    $id,
                ));
            }

            return new ObjectId($id);
        }

        return match ($kind) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
