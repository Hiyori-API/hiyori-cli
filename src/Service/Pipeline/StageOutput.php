<?php

namespace Hiyori\Service\Pipeline;

/**
 * Turns a stage's captured stdout into the structured value the run record
 * carries. Pure functions only, so the record shape is unit-testable without a
 * database or a console.
 */
final class StageOutput
{
    /**
     * Decode the JSON a `--json` stage printed, or null when it printed prose.
     *
     * @return array<mixed>|null
     */
    public static function json(string $output): ?array
    {
        $trimmed = trim($output);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Parse the coverage line `combine` prints, so a failed coverage gate is
     * legible in the run record without re-reading the log.
     *
     * @return array<string, int|float>|null
     */
    public static function combineCoverage(string $output): ?array
    {
        $pattern = '/Coverage:\s*([\d,]+) of ([\d,]+) base rows covered \(([\d.]+)%\)\s*—\s*'
            . 'merged rows:\s*([\d,]+),\s*single-source rows:\s*([\d,]+),\s*'
            . 'unmatched rows:\s*([\d,]+),\s*skipped rows \(no base reference id\):\s*([\d,]+)/u';

        if (preg_match($pattern, $output, $m) !== 1) {
            return null;
        }

        $int = static fn (string $value): int => (int) str_replace(',', '', $value);

        return [
            'covered' => $int($m[1]),
            'examined' => $int($m[2]),
            'coverage_percent' => (float) $m[3],
            'merged' => $int($m[4]),
            'passthrough' => $int($m[5]),
            'unmatched' => $int($m[6]),
            'unresolved_base_id' => $int($m[7]),
        ];
    }

    /** Keep a long transcript from bloating the record. */
    public static function excerpt(string $output, int $limit = 4000): string
    {
        $trimmed = trim($output);
        if (strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        return substr($trimmed, 0, $limit) . "\n… (truncated)";
    }
}
