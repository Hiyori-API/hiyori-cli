<?php

namespace Hiyori\Service\Pipeline;

/**
 * Writes the single run record to `storage/state/`.
 *
 * Two paths hold the same object: an immutable `pipeline-release-<run_id>.json`
 * for history, and `pipeline-release-latest.json` as a stable pointer CI and
 * the shell scripts can read without globbing.
 */
final class RunRecordStore
{
    public function __construct(private string $directory)
    {
    }

    public function pathFor(string $runId): string
    {
        return rtrim($this->directory, '/') . '/pipeline-release-' . $runId . '.json';
    }

    public function latestPath(): string
    {
        return rtrim($this->directory, '/') . '/pipeline-release-latest.json';
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return string the history path written
     */
    public function write(array $record): string
    {
        $runId = (string) ($record['run_id'] ?? gmdate('Ymd-His'));
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('the run record could not be encoded as JSON');
        }

        $this->ensureDirectory();
        $path = $this->pathFor($runId);
        $this->atomicWrite($path, $json . "\n");
        $this->atomicWrite($this->latestPath(), $json . "\n");

        return $path;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('cannot create the state directory: ' . $this->directory);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException('cannot write the run record to ' . $tmp);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException('cannot move the run record into place at ' . $path);
        }
    }
}
