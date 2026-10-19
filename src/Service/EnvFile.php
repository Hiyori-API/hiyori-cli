<?php

namespace Hiyori\Service;

/**
 * Owner of the `.env` file: parsing, masking and atomic rewrites.
 *
 * This logic used to live twice — once in the dashboard front controller
 * (public/index.php) and once nowhere else — so the HTTP layer could rewrite
 * `.env` while an ingest process was reading it. Writes now go through a temp
 * file in the same directory followed by a rename, which is atomic on POSIX.
 */
final class EnvFile
{
    private string $file;

    private string $settingsFile;

    /**
     * @param string|null $file         path to the `.env` file
     * @param string|null $settingsFile `ui.json` holding `env_sensitive_patterns`
     */
    public function __construct(?string $file = null, ?string $settingsFile = null)
    {
        $root = dirname(__DIR__, 2);
        $this->file = $file ?? $root . '/.env';
        $this->settingsFile = $settingsFile ?? $root . '/storage/state/ui.json';
    }

    /**
     * Parse the file into key => raw value. Blank lines, comments and lines
     * without an `=` are skipped, matching the previous listEnvVars().
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $out = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $out[trim($key)] = $value;
        }

        return $out;
    }

    /** The file verbatim, or an empty string when it does not exist. */
    public function raw(): string
    {
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    /**
     * Set an existing key in place or append it. Values are single-line only:
     * a CR/LF would inject extra variables into the file, so it is rejected.
     *
     * @return bool false when the file does not exist (matching updateDotEnv)
     */
    public function set(string $key, string $value): bool
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new \InvalidArgumentException('value must be a single line');
        }

        if (!is_file($this->file)) {
            return false;
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES) ?: [];
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '=/', $line)) {
                $lines[$i] = $key . '=' . $value;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lines[] = $key . '=' . $value;
        }

        $this->write(implode("\n", $lines) . "\n");

        return true;
    }

    /**
     * Drop every `KEY=` line. Blank and comment lines are preserved, matching
     * the previous removeEnvVar().
     */
    public function remove(string $key): void
    {
        if (!is_file($this->file)) {
            return;
        }

        $lines = array_filter(file($this->file, FILE_IGNORE_NEW_LINES) ?: [], static function ($line) use ($key) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                return true;
            }

            return !str_starts_with($trimmed, $key . '=');
        });

        $this->write(implode("\n", array_values($lines)) . "\n");
    }

    /** Replace the whole file with `$raw`, trimmed and newline-terminated. */
    public function replace(string $raw): void
    {
        $this->write(rtrim($raw) . "\n");
    }

    /**
     * The names of keys in the file that look sensitive.
     *
     * @return list<string>
     */
    public function sensitiveKeys(): array
    {
        $keys = [];
        foreach (array_keys($this->all()) as $key) {
            if ($this->isSensitiveKey($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * A key is sensitive when it matches the built-in pattern or one of the
     * dashboard-configured `env_sensitive_patterns` regexes.
     */
    public function isSensitiveKey(string $key): bool
    {
        if ((bool) preg_match('/(TOKEN|SECRET|PASSWORD|CLIENT_SECRET)/i', $key)) {
            return true;
        }

        foreach ($this->sensitivePatterns() as $pattern) {
            if (@preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask a secret for display. Short values reveal nothing: the old helper
     * echoed the first four characters, which for a five-character token was
     * 80% of the value. Only values long enough that four characters is a
     * minor fraction (>= 12) keep a prefix.
     */
    public function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) < 12) {
            return '••••';
        }

        return substr($value, 0, 4) . '••••(' . strlen($value) . ' chars)';
    }

    /**
     * @return list<string>
     */
    private function sensitivePatterns(): array
    {
        if (!is_file($this->settingsFile)) {
            return [];
        }

        $ui = json_decode((string) file_get_contents($this->settingsFile), true);
        if (!is_array($ui)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', (array) ($ui['env_sensitive_patterns'] ?? []))));
    }

    /**
     * Write atomically: temp file in the same directory, then rename. A reader
     * either sees the old file or the new one, never a half-written one.
     */
    private function write(string $contents): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }

        if (is_file($this->file)) {
            @copy($this->file, $this->file . '.bak');
        }

        $tmp = @tempnam($dir, '.env.');
        if ($tmp === false) {
            return;
        }

        if (false === @file_put_contents($tmp, $contents)) {
            @unlink($tmp);

            return;
        }

        @chmod($tmp, 0o600);
        @rename($tmp, $this->file);
    }
}
