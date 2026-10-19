<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * The single owner of "what backups exist".
 *
 * The dashboard asked this question twice (the summary card and the
 * restorable list) and the CLI's `backup` command picked its own default
 * archive path, so the notion of a backup lived in three files that could
 * drift apart. This service knows where the archives live, which ones are
 * restorable and what the newest one is.
 *
 * Any `.gz` in the backups directory counts as a backup, not just the
 * `hiyori-<timestamp>.gz` archives this application writes: a manual
 * pre-rebuild snapshot is a valid restore source too.
 */
final class Backup
{
    /** Archives live here, relative to the repository root. */
    public const DIRECTORY = 'storage/backups';

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/' . self::DIRECTORY;
    }

    /** The directory holding the archives. */
    public function directory(): string
    {
        return $this->dir;
    }

    /** Create the archives directory when it does not exist yet. */
    public function ensureDirectory(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0o777, true);
        }
    }

    /**
     * The path a new archive should be written to.
     *
     * @param string|null $timestamp `Ymd-His` stamp; defaults to now
     */
    public function defaultArchivePath(?string $timestamp = null, bool $gzip = true): string
    {
        $stamp = $timestamp ?? date('Ymd-His');

        return $this->dir . '/hiyori-' . $stamp . ($gzip ? '.gz' : '');
    }

    /**
     * Every restorable archive, newest first.
     *
     * @return list<array{file: string, mtime: int, size: int}>
     */
    public function archives(): array
    {
        $files = glob($this->dir . '/*.gz') ?: [];
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) - (int) filemtime($a));

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'file' => basename($file),
                'mtime' => (int) filemtime($file),
                'size' => (int) filesize($file),
            ];
        }

        return $out;
    }

    /**
     * The summary the dashboard shows: does a backup exist, which one is the
     * newest, and how many there are.
     *
     * @return array{exists: bool, last: ?array{file: string, mtime: int, size: int}, count: int}
     */
    public function info(): array
    {
        $archives = $this->archives();
        $last = $archives[0] ?? null;

        return [
            'exists' => $last !== null,
            'last' => $last,
            'count' => count($archives),
        ];
    }
}
