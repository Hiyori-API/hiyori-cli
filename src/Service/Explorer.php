<?php

declare(strict_types=1);

namespace Hiyori\Service;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;

/**
 * Pure (DB-less) helpers powering the dashboard Explorer endpoints.
 *
 * Keeping the filter/facet/CSV logic here makes it unit-testable without a
 * MongoDB connection while index.php stays a thin front controller.
 */
final class Explorer
{
    public const MAX_FACET_VALUES = 12;

    public const MAX_IMPORT_ROWS = 10000;

    /**
     * Validate a collection name for use in route params. Returns the name
     * when it matches the safe charset, '' otherwise.
     */
    public static function collectionName(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            return '';
        }

        return $name;
    }

    /**
     * Validate a simple field path ([A-Za-z0-9_.]+, dots allowed, e.g.
     * start_date.year). Returns the path when valid, '' otherwise.
     */
    public static function fieldPath(string $field): string
    {
        if (preg_match('/^[A-Za-z0-9_.]+$/', $field) !== 1) {
            return '';
        }

        return $field;
    }

    /**
     * Coerce a string id into an ObjectId when it looks like one, leaving
     * other ids untouched so string-keyed documents still match.
     *
     * @return ObjectId|string
     */
    public static function docIdValue(string $id): ObjectId|string
    {
        if (preg_match('/^[a-f0-9]{24}$/i', $id) === 1) {
            try {
                return new ObjectId($id);
            } catch (\Throwable) {
                // fall through to the raw string
            }
        }

        return $id;
    }

    /**
     * Build the MongoDB filter for the explorer list query: free-text search,
     * the prebuilt missing/has filters and the numeric range params
     * (episodes_min/max, year_min/max).
     *
     * Ranges apply to `episodes` and to `year` OR `start_date.year`, whichever
     * the source collection actually carries.
     *
     * @param array<string, mixed> $q
     *
     * @return array<string, mixed>
     */
    public static function buildFilter(array $q): array
    {
        $search = trim((string) ($q['q'] ?? ''));
        $filter = (string) ($q['filter'] ?? '');
        $mongoFilter = [];

        if ($search !== '') {
            $rx = new Regex(preg_quote($search, '/'), 'i');
            $or = [
                ['title' => $rx],
                ['title.romaji' => $rx],
                ['title.english' => $rx],
                ['title.native' => $rx],
                ['synonyms' => $rx],
                ['_id' => $rx],
            ];
            foreach (['al', 'mal', 'k', 'ann', 'wpen', 'wpja', 'adb', 'cr', 'nf', 'sb', 'ttv', 'tx', 'b', 'fm', 'yt', 'tvt', 'ap', 't'] as $sh) {
                $or[] = ['reference_ids.' . $sh => $rx];
            }
            $mongoFilter['$or'] = $or;
        }

        switch ($filter) {
            case 'no_mal':
                $mongoFilter['reference_ids.mal'] = ['$exists' => false];
                break;
            case 'no_al':
                $mongoFilter['reference_ids.al'] = ['$exists' => false];
                break;
            case 'no_k':
                $mongoFilter['reference_ids.k'] = ['$exists' => false];
                break;
            case 'has_mal':
                $mongoFilter['reference_ids.mal'] = ['$exists' => true];
                break;
            case 'no_refs':
                $mongoFilter = ['reference_ids' => ['$exists' => false]];
                break;
            case 'empty_title':
                $mongoFilter['title'] = ['$in' => [null, '', []]];
                break;
        }

        $range = self::rangeConditions($q);
        if ($range !== []) {
            $mongoFilter = $mongoFilter === [] ? $range : ['$and' => [$mongoFilter, $range]];
        }

        return $mongoFilter;
    }

    /**
     * Aggregation pipeline for one facet: distinct string values + counts for
     * a field, honouring the current list filter, top N by count.
     *
     * @param array<string, mixed> $mongoFilter
     *
     * @return list<array<string, mixed>>
     */
    public static function facetPipeline(string $field, array $mongoFilter): array
    {
        $textOnly = [$field => ['$type' => 'string', '$nin' => ['']]];
        $match = $mongoFilter === [] ? $textOnly : ['$and' => [$mongoFilter, $textOnly]];

        return [
            ['$match' => $match],
            ['$group' => ['_id' => '$' . $field, 'n' => ['$sum' => 1]]],
            ['$sort' => ['n' => -1]],
            ['$limit' => self::MAX_FACET_VALUES],
        ];
    }

    /**
     * Parse CSV (quoted fields, escaped quotes, CRLF/LF, embedded newlines in
     * quotes) into a list of header-keyed associative rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseCsv(string $csv, int $cap = self::MAX_IMPORT_ROWS): array
    {
        $records = self::csvRecords($csv, $cap);
        if ($records === []) {
            return [];
        }

        $header = array_shift($records);
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($header as $i => $column) {
                $row[$column] = $record[$i] ?? '';
            }
            $rows[] = $row;
            if (count($rows) >= $cap) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $q
     *
     * @return array<string, mixed>
     */
    private static function rangeConditions(array $q): array
    {
        $and = [];

        $episodes = self::rangeCond($q, 'episodes_min', 'episodes_max');
        if ($episodes !== null) {
            $and[] = ['episodes' => $episodes];
        }

        $year = self::rangeCond($q, 'year_min', 'year_max');
        if ($year !== null) {
            $and[] = ['$or' => [['year' => $year], ['start_date.year' => $year]]];
        }

        if ($and === []) {
            return [];
        }

        return count($and) === 1 ? $and[0] : ['$and' => $and];
    }

    /**
     * @param array<string, mixed> $q
     *
     * @return array<string, int>|null
     */
    private static function rangeCond(array $q, string $minKey, string $maxKey): ?array
    {
        $min = self::numericBound($q, $minKey);
        $max = self::numericBound($q, $maxKey);
        if ($min === null && $max === null) {
            return null;
        }

        $cond = [];
        if ($min !== null) {
            $cond['$gte'] = $min;
        }
        if ($max !== null) {
            $cond['$lte'] = $max;
        }

        return $cond;
    }

    /**
     * @param array<string, mixed> $q
     */
    private static function numericBound(array $q, string $key): ?int
    {
        if (!array_key_exists($key, $q)) {
            return null;
        }
        $value = $q[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<list<string>>
     */
    private static function csvRecords(string $csv, int $cap): array
    {
        $records = [];
        $record = [];
        $cell = '';
        $inQuotes = false;
        $len = strlen($csv);
        $i = 0;

        while ($i < $len) {
            $c = $csv[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    if ($i + 1 < $len && $csv[$i + 1] === '"') {
                        $cell .= '"';
                        $i += 2;
                        continue;
                    }
                    $inQuotes = false;
                    ++$i;
                    continue;
                }
                $cell .= $c;
                ++$i;
                continue;
            }

            if ($c === '"' && $cell === '') {
                $inQuotes = true;
                ++$i;
                continue;
            }

            if ($c === ',') {
                $record[] = $cell;
                $cell = '';
                ++$i;
                continue;
            }

            if ($c === "\n" || $c === "\r") {
                if ($c === "\r" && $i + 1 < $len && $csv[$i + 1] === "\n") {
                    ++$i;
                }
                $record[] = $cell;
                $cell = '';
                $inQuotes = false;
                if ($record !== ['']) {
                    $records[] = $record;
                    if (count($records) >= $cap + 1) {
                        break;
                    }
                }
                $record = [];
                ++$i;
                continue;
            }

            $cell .= $c;
            ++$i;
        }

        if ($cell !== '' || $record !== []) {
            $record[] = $cell;
            if ($record !== ['']) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
