<?php

declare(strict_types=1);

namespace Hiyori\Service;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;

/**
 * Minimal, dependency-free telemetry store for live pipeline state.
 *
 * Writes atomic JSON state files under storage/state/ along with an
 * append-only JSONL log. Readers (the dashboard) poll these cheap files.
 *
 * Every mutation is a read-merge-write, so it is serialised with an exclusive
 * advisory lock taken before the state file is read. Without that, two workers
 * can read the same old value and the later writer silently drops the other's
 * field (the ingest-parallel.sh case).
 *
 * The lock lives on a sidecar `<file>.lock`, never on `<file>` itself: writes
 * publish with rename(), which replaces the inode. A lock held on the old
 * inode would stop guarding the path as soon as another writer renamed a new
 * file into place, letting two processes hold "the" lock at once. The sidecar
 * is never renamed, so all writers lock the same inode. Lock files are
 * deliberately never unlinked: removing one has the same re-inode race.
 */
final class Telemetry
{
    /**
     * Largest state file `allState()` will inline. The dashboard ships the whole
     * map in /api/status and on every SSE frame, so a single oversized blob (a
     * multi-megabyte cache, say) would otherwise turn each poll into tens of
     * megabytes. Job state is a few KB; anything past this is not job state.
     */
    private const MAX_STATE_BYTES = 1_048_576; // 1 MiB

    /**
     * A JSONL log larger than this is rewritten down to its newest lines.
     */
    private const APPEND_ROTATE_BYTES = 524_288; // 512 KiB

    private string $dir;

    private ?LoggerInterface $logger;

    public function __construct(?string $dir = null, ?LoggerInterface $logger = null)
    {
        // Tests run the real strategies against throwaway collections; an
        // explicit override keeps their telemetry out of the live state
        // directory the dashboard polls, where it would otherwise be served as
        // the latest combine.
        $override = getenv('HIYORI_TELEMETRY_DIR');
        $this->dir = $dir ?? (is_string($override) && $override !== '' ? $override : dirname(__DIR__, 2) . '/storage/state');
        $this->logger = $logger;
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
        $this->mutate($key, static function (array $state) use ($data): array {
            return array_merge($state, $data);
        });
    }

    /**
     * Atomically add $amount to the integer field $field in $key.json.
     *
     * The read and the write happen inside one lock, so parallel workers
     * cannot lose each other's increments. Extra fields in $data are merged
     * like update() does, after the increment.
     */
    public function increment(string $key, string $field, int $amount = 1, array $data = []): void
    {
        $this->mutate($key, static function (array $state) use ($field, $amount, $data): array {
            $state[$field] = (int) ($state[$field] ?? 0) + $amount;

            return array_merge($state, $data);
        });
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
        if (!$this->ensureDir()) {
            return;
        }

        $file = $this->dir . '/' . $name . '.jsonl';
        $event['ts'] = date('c');
        $line = json_encode($event);
        if (false === $line) {
            $this->logFailure('encode', $file);

            return;
        }

        $this->withLock($file, function () use ($file, $line, $maxLines): void {
            error_clear_last();
            if (false === @file_put_contents($file, $line . "\n", FILE_APPEND)) {
                $this->logFailure('append', $file);

                return;
            }

            $size = @filesize($file);
            if (false === $size || $size <= self::APPEND_ROTATE_BYTES) {
                return;
            }

            $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $lines = array_slice($lines, -$maxLines);
            $tmp = $file . '.tmp';
            error_clear_last();
            if (false === @file_put_contents($tmp, implode("\n", $lines) . "\n")) {
                $this->logFailure('rewrite', $file);
                @unlink($tmp);

                return;
            }

            error_clear_last();
            if (!@rename($tmp, $file)) {
                $this->logFailure('rename', $file);
                @unlink($tmp);
            }
        });
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
        $this->mutate($key, static function (array $state) use ($value, $maxPoints): array {
            $history = $state['history'] ?? [];
            $history[] = ['t' => time(), 'v' => $value];
            if (count($history) > $maxPoints) {
                $history = array_slice($history, -$maxPoints);
            }

            return array_merge($state, ['history' => $history]);
        });
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
            if (@filesize($file) > self::MAX_STATE_BYTES) {
                continue; // not job state; never inline it into a polled payload
            }
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
        return self::pausedIn((new self())->stateDir(), $source);
    }

    /**
     * The control state of a source, as the dashboard renders it.
     *
     * @return array{paused: bool}
     */
    public function control(string $source): array
    {
        return ['paused' => self::pausedIn($this->dir, $source)];
    }

    /**
     * Append one entry to the audit log (`audit.jsonl`).
     *
     * The audit log is the dashboard's record of who changed what: settings
     * writes, exports, restores, backups, Explorer edits. It is written
     * under the same lock as every other state file, so two concurrent
     * requests cannot interleave half a line.
     *
     * @param array<string, mixed> $detail
     */
    public function audit(string $action, array $detail = [], ?string $ip = null): void
    {
        if (!$this->ensureDir()) {
            return;
        }

        $file = $this->dir . '/audit.jsonl';
        $line = json_encode([
            'ts' => date('c'),
            'action' => $action,
            'ip' => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES);
        if (false === $line) {
            $this->logFailure('encode', $file);

            return;
        }

        $this->withLock($file, function () use ($file, $line): void {
            error_clear_last();
            if (false === @file_put_contents($file, $line . "\n", FILE_APPEND)) {
                $this->logFailure('append', $file);
            }
        });
    }

    /**
     * Parse the last $n JSON lines of a JSONL log.
     *
     * `total` counts every non-empty line in the file, so callers can be honest
     * about truncation ("newest 500 of 2,377") instead of implying the tail is all.
     *
     * @return array{total: int, events: list<array<string, mixed>>}
     */
    public function tailLinesMeta(string $name, int $n): array
    {
        $file = $this->dir . '/' . $name . '.jsonl';
        if (!is_file($file)) {
            return ['total' => 0, 'events' => []];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $cut = count($lines) - $n;
        $total = 0;
        $events = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            ++$total;
            if ($i < $cut) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return ['total' => $total, 'events' => $events];
    }

    /**
     * Whether the control file of a source says it is paused.
     */
    private static function pausedIn(string $dir, string $source): bool
    {
        $file = $dir . '/control-' . $source . '.json';
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

    /**
     * Read, transform and write one state file while holding its lock.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function mutate(string $key, callable $mutator): void
    {
        if (!$this->ensureDir()) {
            return;
        }

        $file = $this->dir . '/' . $key . '.json';
        $this->withLock($file, function () use ($key, $file, $mutator): void {
            // Re-read inside the lock: a value read before locking may already
            // be stale by the time this process writes.
            $state = $this->read($key);
            $state = $mutator($state);
            $state['updated_at'] = date('c');
            $this->writeAtomically($file, $state);
        });
    }

    /**
     * Publish a state file without ever exposing a half-written file.
     */
    private function writeAtomically(string $file, array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            $this->logFailure('encode', $file);

            return;
        }

        $tmp = $file . '.tmp';
        error_clear_last();
        if (false === @file_put_contents($tmp, $json)) {
            $this->logFailure('write', $file);
            @unlink($tmp);

            return;
        }

        error_clear_last();
        if (!@rename($tmp, $file)) {
            $this->logFailure('rename', $file);
            @unlink($tmp);
        }
    }

    /**
     * Run $fn while holding an exclusive lock on $file's sidecar lock file.
     */
    private function withLock(string $file, callable $fn): void
    {
        $lockFile = $file . '.lock';
        $handle = @fopen($lockFile, 'c');
        if (false === $handle) {
            $this->logFailure('lock-open', $lockFile);

            return;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                $this->logFailure('lock-acquire', $lockFile);

                return;
            }

            try {
                $fn();
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            @fclose($handle);
        }
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        if (@mkdir($this->dir, 0o777, true) || is_dir($this->dir)) {
            return true;
        }

        $this->logFailure('mkdir', $this->dir);

        return false;
    }

    /**
     * Report a failed write instead of swallowing it. Telemetry must never
     * throw into an ingest, so even the logger is best-effort.
     */
    private function logFailure(string $operation, string $path): void
    {
        $error = error_get_last();

        try {
            $this->logger()->error('telemetry ' . $operation . ' failed', [
                'path' => $path,
                'error' => null !== $error ? $error['message'] : 'unknown error',
            ]);
        } catch (\Throwable) {
            // A broken logger must not take down the caller either.
        }
    }

    /**
     * Without a handler a Monolog logger silently discards every record, so a
     * default logger writes beside the main log like RateLimiter does.
     */
    private function logger(): LoggerInterface
    {
        if (null !== $this->logger) {
            return $this->logger;
        }

        $logger = new MonologLogger('telemetry');
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0o777, true);
        }
        if (is_dir($logDir)) {
            $logger->pushHandler(new RotatingFileHandler($logDir . '/telemetry', 5));
        }
        $this->logger = $logger;

        return $logger;
    }
}
