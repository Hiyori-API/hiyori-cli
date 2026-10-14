<?php

declare(strict_types=1);

/**
 * Pure helpers for the export/publish API surface.
 *
 * Everything in this file is side-effect free: it only declares a constant and
 * functions, so it is safe to `require_once` from both the router
 * (`public/index.php`) and the unit tests. HTTP/JSON and file-system work stays
 * in `public/index.php`; the functions here are pure so they can be tested
 * without a database or a running `\Hiyori\Service\Exporter`.
 */

/**
 * Fallback list of export profiles. Only used when
 * `\Hiyori\Service\ExportConfig` does not expose its own list via the
 * `PROFILES` constant or a static `profiles()` method.
 *
 * @var array<int, string>
 */
const HIYORI_EXPORT_PROFILES = ['per-entry-json', 'bulk-jsonl', 'tables-csv'];

/**
 * Known export profile names, preferring `ExportConfig`'s authoritative list
 * when the class is present.
 *
 * @return array<int, string>
 */
function hiyoriExportProfiles(): array
{
    $class = 'Hiyori\\Service\\ExportConfig';
    if (class_exists($class)) {
        if (method_exists($class, 'profileNames')) {
            try {
                $names = $class::profileNames();
                $names = is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
                if ($names !== []) {
                    return $names;
                }
            } catch (\Throwable $e) {
                // fall through to the constant / built-in list
            }
        }
        if (defined($class . '::PROFILES')) {
            $constant = constant($class . '::PROFILES');
            if (is_array($constant) && $constant !== []) {
                // `PROFILES` is a name => preset map; a plain list is also accepted.
                $names = array_is_list($constant)
                    ? array_values(array_filter($constant, 'is_string'))
                    : array_keys($constant);
                if ($names !== []) {
                    return $names;
                }
            }
        }
    }

    return HIYORI_EXPORT_PROFILES;
}

/**
 * Validate a configured output filename pattern. Returns `null` when valid,
 * otherwise the human-readable problem. The only hard requirement is the
 * `{hiyori_id}` placeholder; relative sub-paths are allowed but traversal is
 * not.
 */
function hiyoriExportFilenameError(string $filename): ?string
{
    $filename = trim($filename);
    if ($filename === '') {
        return 'filename must not be empty';
    }
    if (!str_contains($filename, '{hiyori_id}')) {
        return 'filename must contain the {hiyori_id} placeholder';
    }
    if (str_contains($filename, '..') || str_contains($filename, '\\') || str_starts_with($filename, '/')) {
        return 'filename must be a relative path without traversal';
    }

    return null;
}

/**
 * Validate the shapes of the keys a client may POST. Unknown keys are ignored
 * (forward compatible); only the documented keys are type-checked.
 *
 * @param array<string, mixed> $config
 * @param array<int, string>   $profiles
 * @return array<int, string>  list of validation errors (empty = valid)
 */
function hiyoriExportConfigErrors(array $config, array $profiles): array
{
    $errors = [];

    if (array_key_exists('profile', $config)) {
        $profile = $config['profile'];
        if (!is_string($profile) || $profile === '') {
            $errors[] = 'profile must be a non-empty string';
        } elseif (!in_array($profile, $profiles, true)) {
            $errors[] = 'profile must be one of: ' . implode(', ', $profiles);
        }
    }

    if (array_key_exists('entities', $config)) {
        $entities = $config['entities'];
        if (!is_array($entities)) {
            $errors[] = 'entities must be an object with anime/manga booleans';
        } else {
            foreach (['anime', 'manga'] as $kind) {
                if (array_key_exists($kind, $entities) && !is_bool($entities[$kind])) {
                    $errors[] = 'entities.' . $kind . ' must be a boolean';
                }
            }
        }
    }

    if (array_key_exists('fields', $config)) {
        $fields = $config['fields'];
        if (!is_array($fields) || !array_is_list($fields)) {
            $errors[] = 'fields must be an array of field names';
        } else {
            foreach ($fields as $field) {
                if (!is_string($field) || trim($field) === '') {
                    $errors[] = 'fields must contain only non-empty strings';
                    break;
                }
            }
        }
    }

    if (array_key_exists('include', $config)) {
        $include = $config['include'];
        if (!is_array($include)) {
            $errors[] = 'include must be an object of flags';
        } else {
            foreach ($include as $key => $value) {
                if (!is_string($key)) {
                    $errors[] = 'include keys must be strings';
                    break;
                }
                if (!is_bool($value) && !is_array($value)) {
                    $errors[] = 'include.' . $key . ' must be a boolean or nested object';
                }
            }
        }
    }

    if (array_key_exists('filename', $config)) {
        $filename = $config['filename'];
        if (!is_string($filename)) {
            $errors[] = 'filename must be a string';
        } else {
            $problem = hiyoriExportFilenameError($filename);
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }
    }

    return $errors;
}

/**
 * Key-wise recursive merge used for the state file and request overrides:
 * associative arrays merge, lists and scalars replace.
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function hiyoriExportMergeDeep(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if (
            is_array($value) && !array_is_list($value)
            && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])
        ) {
            /** @var array<string, mixed> $existing */
            $existing = $base[$key];
            $base[$key] = hiyoriExportMergeDeep($existing, $value);
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}
