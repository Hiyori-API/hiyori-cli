<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner;

/**
 * What a single combine run did with its base rows.
 *
 * Every counter is per BASE ROW, never per write: `metadata_fuzzy` upserts one
 * document once per matching source, so a write counter over-reports coverage
 * (an entry matching both AniList and Kitsu writes twice but covers one row).
 * The fields are defined so this identity holds for every strategy, and
 * `CombineStatsTest` pins it:
 *
 *   examined === merged + passthrough + unmatched + unresolvedBaseId
 *   written  === merged + passthrough
 *
 * `unresolvedBaseId` is the silent-loss bucket: a base row whose own reference
 * id cannot be resolved (`Helper::resolveReferenceId()` returns '') cannot key
 * an upsert document, so every strategy skips it whether or not it matched.
 * Until now that was counted nowhere, which is why a run could drop 40 % of its
 * base and still report success.
 */
final class CombineStats
{
    public function __construct(
        public readonly string $strategy,
        public readonly string $base,
        public readonly int $examined,
        public readonly int $merged,
        public readonly int $passthrough,
        public readonly int $unmatched,
        public readonly int $unresolvedBaseId,
    ) {
    }

    /**
     * Build stats from the counters a strategy already keeps.
     *
     * `unmatched` is derived rather than counted: it is the remainder of the
     * base rows that were neither written nor skipped for lack of an id.
     */
    public static function forRun(
        string $strategy,
        string $base,
        int $examined,
        int $merged,
        int $passthrough,
        int $unresolvedBaseId,
    ): self {
        return new self(
            $strategy,
            $base,
            $examined,
            $merged,
            $passthrough,
            max(0, $examined - $merged - $passthrough - $unresolvedBaseId),
            $unresolvedBaseId,
        );
    }

    /** Base rows that ended up represented by a document. */
    public function written(): int
    {
        return $this->merged + $this->passthrough;
    }

    /** Written / examined as a percentage; 0.0 for an empty base. */
    public function coverage(): float
    {
        if ($this->examined <= 0) {
            return 0.0;
        }

        return $this->written() / $this->examined * 100.0;
    }

    public function coveragePercent(int $precision = 1): string
    {
        return number_format($this->coverage(), $precision, '.', '');
    }

    /**
     * True when the run must be reported as a failure.
     *
     * A run that examined nothing always fails, even with `--min-coverage=0`:
     * `0` opts out of the "how much" comparison, not out of the requirement
     * that the run did something. Both cases used to exit 0.
     */
    public function failsCoverageGate(int $floorPercent): bool
    {
        if ($this->examined <= 0) {
            return true;
        }
        if ($floorPercent <= 0) {
            return false;
        }

        return $this->coverage() < (float) $floorPercent;
    }

    /** @return array<string, int|float|string> */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'base' => $this->base,
            'examined' => $this->examined,
            'written' => $this->written(),
            'merged' => $this->merged,
            'passthrough' => $this->passthrough,
            'unmatched' => $this->unmatched,
            'unresolved_base_id' => $this->unresolvedBaseId,
            'coverage_percent' => round($this->coverage(), 2),
        ];
    }
}
