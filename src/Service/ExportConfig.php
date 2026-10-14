<?php

namespace Hiyori\Service;

/**
 * Effective export configuration.
 *
 * The tracked repo file `hiyori.export.json` is merged with server-state
 * overrides in `storage/state/export.json` (state wins), then optionally with
 * CLI `--config k=v` overrides. Profiles bundle a layout/format/pretty set of
 * defaults that individual keys can still override.
 */
final class ExportConfig
{
    public const DEFAULT_PROFILE = 'per-entry-json';

    public const TRACKED_FILE = 'hiyori.export.json';

    public const STATE_FILE = 'storage/state/export.json';

    /** Keys derived from the active profile unless explicitly set. */
    private const PROFILE_KEYS = ['layout', 'format', 'index', 'pretty', 'bundle', 'write_entries'];

    /**
     * Profile defaults. `write_entries` controls whether the primary artifact
     * is one file per entry (per-entry-json) or only the bulk/tables form.
     *
     * @var array<string, array<string, mixed>>
     */
    public const PROFILES = [
        'per-entry-json' => [
            'layout' => '{subdir}/{kind}/{filename}.json',
            'format' => 'json',
            'index' => true,
            'pretty' => true,
            'bundle' => true,
            'write_entries' => true,
        ],
        'bulk-jsonl' => [
            'layout' => '{subdir}/{kind}.jsonl',
            'format' => 'jsonl',
            'index' => true,
            'pretty' => false,
            'bundle' => true,
            'write_entries' => false,
        ],
        'tables-csv' => [
            'layout' => '{subdir}/tables/{kind}.csv',
            'format' => 'csv',
            'index' => true,
            'pretty' => false,
            'bundle' => true,
            'write_entries' => false,
        ],
    ];

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'profile' => self::DEFAULT_PROFILE,
        'subdir' => 'data',
        'filename' => '{hiyori_id}-{slug}',
        'fields' => [],
        'entities' => ['anime' => true, 'manga' => true],
        'include' => [
            'titles' => true,
            'synonyms' => true,
            'references' => true,
            'links' => true,
            'tags' => false,
            'genres' => false,
            'synopsis' => false,
            'score' => false,
            'studios' => false,
        ],
        'index' => true,
        'pretty' => true,
        'bundle' => true,
        'combiner' => ['auto_export' => null],
    ];

    /**
     * Load the effective config: defaults <- tracked file <- server state.
     *
     * @return array<string, mixed>
     */
    public static function load(?string $stateFile = null, ?string $trackedFile = null): array
    {
        $config = self::merge([], self::readJson($trackedFile ?? self::trackedPath()));
        $config = self::merge($config, self::readJson($stateFile ?? self::statePath()));

        return self::resolve($config);
    }

    /**
     * Resolved defaults (no files read) — handy for tests and callers that
     * only need a base config to tweak.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::resolve(self::DEFAULTS);
    }

    /**
     * Fill in every known key, pick the profile layout/format defaults for any
     * key the caller did not set explicitly, and normalise types.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $config): array
    {
        $given = $config;

        $config = self::merge(self::DEFAULTS, $config);

        $profile = is_string($config['profile'] ?? null) ? $config['profile'] : self::DEFAULT_PROFILE;
        if (!isset(self::PROFILES[$profile])) {
            $profile = self::DEFAULT_PROFILE;
        }
        $config['profile'] = $profile;

        $profileDefaults = self::PROFILES[$profile];
        foreach (self::PROFILE_KEYS as $key) {
            if (!array_key_exists($key, $given) || $given[$key] === null || $given[$key] === '') {
                $config[$key] = $profileDefaults[$key];
            }
        }

        $config['index'] = (bool) $config['index'];
        $config['pretty'] = (bool) $config['pretty'];
        $config['bundle'] = (bool) $config['bundle'];
        $config['write_entries'] = (bool) $config['write_entries'];
        $config['subdir'] = trim((string) $config['subdir'], '/') ?: 'data';
        $config['filename'] = (string) $config['filename'];
        $config['fields'] = is_array($config['fields']) ? array_values($config['fields']) : [];
        $config['entities'] = [
            'anime' => (bool) ($config['entities']['anime'] ?? true),
            'manga' => (bool) ($config['entities']['manga'] ?? true),
        ];
        $config['include'] = self::merge(self::DEFAULTS['include'], is_array($config['include']) ? $config['include'] : []);
        foreach ($config['include'] as $key => $value) {
            $config['include'][$key] = (bool) $value;
        }
        $config['combiner'] = [
            'auto_export' => $config['combiner']['auto_export'] ?? null,
        ];

        return $config;
    }

    /**
     * Recursive merge where state/overlay wins. Associative maps recurse;
     * lists (and scalars) are replaced wholesale by the overlay.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = self::merge($base[$key], $value);

                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Apply `--config key=value` overrides (dot notation, e.g.
     * `include.tags=true`, `entities.manga=false`).
     *
     * @param array<string, mixed> $config
     * @param iterable<string>     $overrides
     *
     * @return array<string, mixed>
     */
    public static function overrides(array $config, iterable $overrides): array
    {
        $profileBefore = is_string($config['profile'] ?? null) ? $config['profile'] : null;

        foreach ($overrides as $pair) {
            if (!is_string($pair) || !str_contains($pair, '=')) {
                continue;
            }
            [$path, $raw] = explode('=', $pair, 2);
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $value = self::cast($raw, $path);
            $segments = explode('.', $path);
            $cursor = &$config;
            $last = count($segments) - 1;
            foreach ($segments as $i => $segment) {
                if ($i === $last) {
                    $cursor[$segment] = $value;

                    break;
                }
                if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }
                $cursor = &$cursor[$segment];
            }
            unset($cursor);
        }

        $profileAfter = is_string($config['profile'] ?? null) ? $config['profile'] : null;
        if ($profileAfter !== null && $profileAfter !== $profileBefore) {
            return self::withProfile($config, $profileAfter);
        }

        return self::resolve($config);
    }

    /**
     * Force a profile on an already-resolved config and re-derive its
     * layout/format/pretty defaults (used by `--profile` and the combiner).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function withProfile(array $config, string $profile): array
    {
        $config['profile'] = $profile;
        foreach (self::PROFILE_KEYS as $key) {
            unset($config[$key]);
        }

        return self::resolve($config);
    }

    /** Map a legacy `--format` value onto a profile name. */
    public static function profileForFormat(string $format): string
    {
        return match (strtolower(trim($format))) {
            'relational', 'tables', 'csv' => 'tables-csv',
            'jsonl', 'bulk' => 'bulk-jsonl',
            default => self::DEFAULT_PROFILE,
        };
    }

    /** @return list<string> */
    public static function profileNames(): array
    {
        return array_keys(self::PROFILES);
    }

    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function trackedPath(): string
    {
        return self::rootDir() . '/' . self::TRACKED_FILE;
    }

    public static function statePath(): string
    {
        return self::rootDir() . '/' . self::STATE_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function cast(string $raw, string $path): mixed
    {
        $trimmed = trim($raw);
        if ($path === 'fields') {
            return array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn (string $v): bool => $v !== ''));
        }
        if ($trimmed === 'true') {
            return true;
        }
        if ($trimmed === 'false') {
            return false;
        }
        if ($trimmed === 'null') {
            return null;
        }
        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        return $trimmed;
    }
}
