<?php

declare(strict_types=1);

namespace Hiyori\Service;

use Hiyori\Service\Ml\BenchmarkEvaluator;

/**
 * The benchmark numbers the dashboard shows.
 *
 * Three reads lived in the front controller: the strategy-benchmark log
 * parser, the payload assembled from it, and the offline ML benchmark report.
 * They are one subject — "how did the last run do" — so they belong to one
 * service.
 *
 * The threshold-sweep rules (which row is the best one, whether the ML
 * scorer clears the baseline) are NOT re-implemented here: they belong to
 * {@see BenchmarkEvaluator}, which produced the report in the first place.
 */
final class Benchmark
{
    /** The anime strategy-benchmark log. */
    public const ANIME_LOG = 'strategy-benchmark-anime.log';

    /** The manga strategy-benchmark log. */
    public const MANGA_LOG = 'strategy-benchmark-manga.log';

    /** The offline ML sweep report (`combine:benchmark --out`). */
    public const ML_REPORT = 'combine-benchmark.json';

    /** How the log is labelled in the API, independent of where it is read from. */
    public const LOG_SOURCE_PREFIX = 'storage/logs/';

    private Telemetry $telemetry;

    private string $logDir;

    private string $stateDir;

    public function __construct(?Telemetry $telemetry = null, ?string $logDir = null, ?string $stateDir = null)
    {
        $root = dirname(__DIR__, 2);
        $this->telemetry = $telemetry ?? new Telemetry();
        $this->logDir = $logDir ?? $root . '/storage/logs';
        $this->stateDir = $stateDir ?? $root . '/storage/state';
    }

    /**
     * Parse a benchmark log.
     *
     * The log interleaves two kinds of line: a `wall: 123s` marker printed
     * just before a strategy's JSON row, and the JSON row itself. The wall
     * clock belongs to the row that follows it.
     *
     * @return list<array<string, mixed>>
     */
    public static function readLog(string $file): array
    {
        $rows = [];
        $walls = [];
        if (!is_file($file)) {
            return $rows;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^wall(?:_[a-z_]+)?:\s*([\d.]+)s/', $line, $m)) {
                $walls[] = (float) $m[1];
            }
            if (!str_starts_with($line, '{')) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['strategy'])) {
                $decoded['wall'] = array_shift($walls) ?? 0.0;
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * The anime benchmark payload (`/api/benchmark`).
     *
     * Only rows a benchmark run actually wrote are returned. This used to merge
     * a hardcoded seed table over the log, which fabricated `merges` for every
     * strategy (the log stores `total`, so the seeded values always won) — the
     * dashboard showed numbers no run had produced. `wall` is the "wall: 123s"
     * marker the benchmark script prints just before each strategy's JSON row.
     *
     * @return array{recorded: list<array{strategy: string, wall_s: int, merges: int, dups: int}>, source: ?string, recorded_at: ?string, latest: array<string, mixed>, ml: ?array<string, mixed>}
     */
    public function anime(): array
    {
        $file = $this->logDir . '/' . self::ANIME_LOG;

        $out = [];
        foreach (self::readLog($file) as $row) {
            $out[] = [
                'strategy' => (string) ($row['strategy'] ?? ''),
                'wall_s' => (int) round((float) ($row['wall'] ?? 0)),
                'merges' => (int) ($row['total'] ?? 0),
                'dups' => (int) ($row['duplicate_keys'] ?? 0),
            ];
        }

        return [
            'recorded' => $out,
            'source' => $out === [] ? null : self::LOG_SOURCE_PREFIX . basename($file),
            'recorded_at' => $out === [] ? null : date('c', (int) filemtime($file)),
            'latest' => $this->telemetry->read('combine-latest'),
            'ml' => $this->mlReport(),
        ];
    }

    /**
     * The manga benchmark payload (`/api/benchmark-manga`).
     *
     * @return array{benchmark: list<array{strategy: string, wall_s: int, merges: int, organized: int, dups: int, speed: float|int}>, source: string}
     */
    public function manga(): array
    {
        $rows = self::readLog($this->logDir . '/' . self::MANGA_LOG);
        $out = [];
        foreach ($rows as $row) {
            $strategy = (string) ($row['strategy'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            $wall = (float) ($row['wall_s'] ?? $row['wall'] ?? 0);
            $out[] = [
                'strategy' => $strategy,
                'wall_s' => (int) $wall,
                'merges' => $total,
                'organized' => $total,
                'dups' => (int) ($row['duplicate_keys'] ?? 0),
                'speed' => $wall > 0 ? round($total / $wall, 2) : 0,
            ];
        }

        return [
            'benchmark' => $out,
            'source' => self::MANGA_LOG,
        ];
    }

    /**
     * The latest offline ML benchmark report (`combine:benchmark`), if any.
     *
     * Exposes the threshold suggestion (best F1 row: threshold, predicted
     * merges, precision/recall/F1) and the shipping gate so the dashboard can
     * show a recommended threshold without re-running the sweep.
     *
     * The "best row" rule is {@see BenchmarkEvaluator::best()}'s — the same
     * one that produced the report — so the dashboard never grows a second
     * definition of "best". A report without a sweep keeps its stored row.
     *
     * @return array<string, mixed>|null
     */
    public function mlReport(): ?array
    {
        $path = $this->stateDir . '/' . self::ML_REPORT;
        if (!is_file($path)) {
            return null;
        }
        $report = json_decode((string) @file_get_contents($path), true);
        if (!is_array($report)) {
            return null;
        }

        $best = [];
        foreach (($report['scorers'] ?? []) as $name => $scorer) {
            if (!is_array($scorer)) {
                continue;
            }
            $row = null;
            if (isset($scorer['sweep']) && is_array($scorer['sweep'])) {
                $row = BenchmarkEvaluator::best($scorer['sweep']);
            }
            if ($row === null && isset($scorer['best']) && is_array($scorer['best'])) {
                $row = $scorer['best'];
            }
            if ($row !== null) {
                $best[(string) $name] = $row;
            }
        }

        return [
            'created_at' => $report['created_at'] ?? null,
            'input' => $report['input'] ?? null,
            'rows' => $report['rows'] ?? null,
            'ml_model_version' => $report['ml_model_version'] ?? null,
            'gate' => $report['gate'] ?? null,
            'best' => $best,
        ];
    }
}
