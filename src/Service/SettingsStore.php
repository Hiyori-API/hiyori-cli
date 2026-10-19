<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * Owner of the dashboard's persisted settings (`storage/state/ui.json`).
 *
 * Three places read or wrote that file: the front controller (the Settings
 * page), the rate limiter (the dashboard's limit override) and the CLI
 * bootstrap (which asks the rate limiter for the effective limits). Each
 * parsed the file its own way, so the file had no owner.
 *
 * The precedence rule for the rate-limit override does NOT live here — that
 * belongs to {@see RateLimiter::effectiveLimits()}, which asks this service
 * for the raw override and applies the rule itself.
 */
final class SettingsStore
{
    /** The settings file, relative to the repository root. */
    public const FILE = 'storage/state/ui.json';

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 2) . '/' . self::FILE;
    }

    /** The path of the settings file. */
    public function path(): string
    {
        return $this->file;
    }

    /**
     * The stored settings, exactly as they are on disk.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The stored settings with the defaults filled in.
     *
     * `_counters`/`_migrations` are internal bookkeeping, not dataset
     * documents, so they start hidden — otherwise the Dataset donut shows
     * meaningless 1-doc and 4-doc slices. Settings → Collections can unhide
     * them.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->read();

        $settings['prefs'] = ($settings['prefs'] ?? []) + [
            'realtime' => true,
            'hiddenCollections' => ['_counters', '_migrations'],
        ];

        return $settings;
    }

    /**
     * Persist the settings.
     *
     * Written atomically (temp file in the same directory, then a rename):
     * the dashboard, the rate limiter and the CLI can all read this file at
     * the same time, and a half-written file would be read as "no settings".
     *
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $tmp = $this->file . '.tmp';
        if (false === @file_put_contents($tmp, $json)) {
            @unlink($tmp);

            return;
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
        }
    }

    /**
     * The stored rate-limit override, raw.
     *
     * Whether the override applies (both `max` and `window` present and
     * positive) is the rate limiter's rule, not this service's.
     *
     * @return array<string, mixed>|null
     */
    public function limitOverride(): ?array
    {
        $override = $this->read()['limits'] ?? null;

        return is_array($override) ? $override : null;
    }
}
