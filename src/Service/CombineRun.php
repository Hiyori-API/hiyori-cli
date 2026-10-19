<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * The single owner of "is a combine running?".
 *
 * Two functions in the front controller asked that question and answered it
 * two different ways:
 *
 *   - the marker the dashboard writes when IT starts a combine
 *     (`storage/state/combine-run.json`, holding the child pid);
 *   - a `pgrep` for any `php hiyori combine` process on the box.
 *
 * They are deliberately kept apart here rather than merged: "the run the
 * dashboard started" and "a run the operator started from a terminal" are
 * different questions. The export route refuses to write a publish dump
 * while ANY combine is running; the status payload reports whether the
 * dashboard's own run is still alive.
 */
final class CombineRun
{
    /** The marker the dashboard writes when it starts a combine. */
    public const MARKER = 'storage/state/combine-run.json';

    /** The command line a combine run is recognised by. */
    public const PATTERN = '[p]hp hiyori combine';

    private string $stateDir;

    public function __construct(?string $stateDir = null)
    {
        $this->stateDir = $stateDir ?? dirname(__DIR__, 2) . '/storage/state';
    }

    /** The path of the run marker. */
    public function markerPath(): string
    {
        return $this->stateDir . '/combine-run.json';
    }

    /**
     * Record a combine the dashboard started.
     *
     * The pid lets `running()` tell a live run from a marker left behind by
     * one that died.
     */
    public function markStarted(int $pid, string $base, string $strategy): void
    {
        $file = $this->markerPath();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($file, json_encode([
            'pid' => $pid,
            'started_at' => date('c'),
            'base' => $base,
            'strategy' => $strategy,
        ]));
    }

    /**
     * Whether the combine the dashboard started is still running.
     *
     * There is no per-run telemetry for combine (unlike ingest, which writes a
     * state file), so the start endpoint stamps a marker with the process id
     * and this checks the process is still alive. A stale marker — the process
     * died, the box rebooted — clears itself rather than leaving the Rebuild
     * button disabled forever.
     */
    public function running(): bool
    {
        $file = $this->markerPath();
        if (!is_file($file)) {
            return false;
        }
        $state = json_decode((string) @file_get_contents($file), true);
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return true;
        }
        @unlink($file);

        return false;
    }

    /**
     * Whether any `php hiyori combine` process is running, whoever started it.
     */
    public function processRunning(): bool
    {
        // The `[p]hp` bracket trick stops pgrep matching its own command line.
        $result = ProcessRunner::run(['pgrep', '-f', self::PATTERN], null, 10.0);

        return $result['exit_code'] === 0 && $result['output'] !== '';
    }
}
