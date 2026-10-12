<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * Minimal, dependency-free telemetry store for live pipeline state.
 *
 * Writes atomic JSON state files under storage/state/ along with an
 * append-only JSONL log. Readers (the dashboard) poll these cheap files.
 */
final class Telemetry
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/storage/state';
    }

    public function stateDir(): string
    {
        return $this->dir;
    }

    /**
     * Merge $data into $key.json (last write wins per field) and stamp updated_at.
     */
    public function update(string $key, array $data): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0o777, true);
        }
        $merged = array_merge($this->read($key), $data, ['updated_at' => date('c')]);
        $file = $this->dir . '/' . $key . '.json';
        $tmp = $file . '.tmp';
        if (false === @file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            return;
        }
        @rename($tmp, $file);
    }

    public function read(string $key): array
    {
        $file = $this->dir . '/' . $key . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Append one JSON line to $name.jsonl (bounded below).
     */
    public function append(string $name, array $event, int $maxLines = 2000): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0o777, true);
        }
        $file = $this->dir . '/' . $name . '.jsonl';
        $event['ts'] = date('c');
        @file_put_contents($file, json_encode($event) . "\n", FILE_APPEND);

        if (@filesize($file) > 512 * 1024) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $lines = array_slice($lines, -$maxLines);
            @file_put_contents($file, implode("\n", $lines) . "\n");
        }
    }

    /**
     * Read the last $limit lines of a JSONL log.
     */
    public function tail(string $name, int $limit = 50): array
    {
        $file = $this->dir . '/' . $name . '.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * Push a timestamped point into a ring-buffer history array stored in $key.json.
     */
    public function pushHistory(string $key, float $value, int $maxPoints = 120): void
    {
        $state = $this->read($key);
        $history = $state['history'] ?? [];
        $history[] = ['t' => time(), 'v' => $value];
        if (count($history) > $maxPoints) {
            $history = array_slice($history, -$maxPoints);
        }
        $this->update($key, ['history' => $history]);
    }

    /**
     * Aggregate every state file into a single map (used by the dashboard).
     *
     * @return array<string, array<string, mixed>>
     */
    public function allState(): array
    {
        $out = [];
        if (!is_dir($this->dir)) {
            return $out;
        }
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $key = basename($file, '.json');
            $out[$key] = $this->read($key);
        }

        return $out;
    }

    /**
     * Whether a source is paused via a control file (dashboard /api/action).
     */
    public static function isPaused(string $source): bool
    {
        $t = new self();
        $file = $t->stateDir() . '/control-' . $source . '.json';
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return (bool) ($data['paused'] ?? false);
    }

    /**
     * Block until the source is resumed (checked once per second).
     */
    public static function waitIfPaused(string $source): void
    {
        while (self::isPaused($source)) {
            sleep(1);
        }
    }

    /**
     * One-shot ingestion progress tracker (convenience statics).
     */
    public static function track(
        string $source,
        int $processed,
        int $total,
        int $errors = 0,
        ?string $phase = null,
        string $mode = 'initial',
        array $extra = [],
        ?string $dir = null
    ): void {
        $t = new self($dir);
        $state = $t->read('ingest-' . $source);
        $started = $state['started_at_ts'] ?? time();
        $elapsed = max(1, time() - $started);
        $rate = $processed / $elapsed;

        $t->update('ingest-' . $source, array_merge([
            'source' => $source,
            'phase' => $phase ?? ($state['phase'] ?? 'running'),
            'mode' => $mode,
            'processed' => $processed,
            'total' => $total,
            'errors' => $errors,
            'per_second' => round($rate, 2),
            'eta_seconds' => $rate > 0 && $total > 0 ? (int) (max(0, $total - $processed) / $rate) : null,
            'started_at_ts' => $started,
        ], $extra));
        $t->pushHistory('ingest-' . $source, $rate);
    }
}
