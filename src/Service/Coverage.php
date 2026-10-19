<?php

declare(strict_types=1);

namespace Hiyori\Service;

use MongoDB\Client as MongoClient;

/**
 * Per-collection reference coverage.
 *
 * "How many merged records carry a MAL / AniList / Kitsu reference?" is the
 * question the Coverage page asks, and it is the single most expensive read
 * the dashboard makes: one `$unwind` aggregation over every collection. It
 * used to be a free function in the front controller, which is why it had no
 * test at all — a file that cannot be included cannot be tested.
 *
 * The service is read-only: it derives the matrix from a single aggregation
 * pass and never writes.
 */
final class Coverage
{
    /** The reference shorthands this application understands. */
    public const SHORTHANDS = [
        'al', 'mal', 'k', 'adb', 'ann', 'b', 'cr', 'fm', 'nf', 'sb',
        't', 'ttv', 'tvt', 'tx', 'wpen', 'wpja', 'yt', 'ap',
    ];

    /** The three main references whose missingness the page reports. */
    public const MAIN_SHORTHANDS = ['al', 'mal', 'k'];

    private MongoClient $client;

    private string $database;

    private Stats $stats;

    public function __construct(MongoClient $client, ?string $database = null, ?Stats $stats = null)
    {
        $this->client = $client;
        $this->database = $database ?? Stats::DATABASE;
        $this->stats = $stats ?? new Stats($client, $this->database);
    }

    /**
     * The aggregation that counts, per collection, how many documents carry
     * each reference shorthand.
     *
     * A doc counts for shorthand <sh> when the *key* is present in its
     * reference_ids map — what `{reference_ids.<sh>: {$exists: true}}` means.
     *
     * Doing that in one pass instead of 22 countDocuments() per collection:
     * normalise reference_ids to a key/value list, unwind, then count distinct
     * (doc, key) pairs. The normalisation matters because some ingesters write
     * `reference_ids: []` (an empty array) rather than `{}` for "no refs", and a
     * dotted path through an array resolves to [] rather than 'missing', so a
     * bare $type check would wrongly count every such document.
     *
     * @return list<array<string, mixed>>
     */
    public static function pipeline(): array
    {
        $keyPairs = [
            '$cond' => [
                ['$eq' => [['$type' => '$reference_ids'], 'object']],
                ['$objectToArray' => '$reference_ids'],
                ['$cond' => [
                    ['$eq' => [['$type' => '$reference_ids'], 'array']],
                    ['$reduce' => [
                        'input' => '$reference_ids',
                        'initialValue' => [],
                        'in' => ['$concatArrays' => ['$$value', ['$cond' => [['$eq' => [['$type' => '$$this'], 'object']], ['$objectToArray' => '$$this'], []]]]],
                    ]],
                    [],
                ]],
            ],
        ];

        return [
            // $setUnion collapses duplicate keys within a document, so a doc that
            // lists the same reference twice is still counted once.
            ['$set' => ['_keys' => ['$setUnion' => [
                ['$map' => ['input' => $keyPairs, 'as' => 'p', 'in' => '$$p.k']],
                [],
            ]]]],
            ['$unwind' => ['path' => '$_keys', 'preserveNullAndEmptyArrays' => false]],
            ['$group' => ['_id' => '$_keys', 'n' => ['$sum' => 1]]],
        ];
    }

    /**
     * The coverage matrix: one row per collection, one column per reference
     * shorthand, plus the derived missingness of the three main references.
     *
     * @param array<string, int>|null $totals Optional map of collection → doc
     *                                        count (from Stats::collectionCounts())
     *                                        to avoid re-counting every
     *                                        collection.
     *
     * @return array<string, array<string, int>>
     */
    public function matrix(?array $totals = null): array
    {
        $db = $this->client->selectDatabase($this->database);
        $totals ??= $this->stats->collectionCounts();
        $pipeline = self::pipeline();

        $out = [];
        foreach ($db->listCollections() as $col) {
            $name = $col->getName();
            $row = ['total' => (int) ($totals[$name] ?? 0)];
            foreach (self::SHORTHANDS as $sh) {
                $row[$sh] = 0;
            }
            try {
                foreach ($db->selectCollection($name)->aggregate($pipeline) as $r) {
                    $k = (string) $r['_id'];
                    if (array_key_exists($k, $row)) {
                        $row[$k] = (int) $r['n'];
                    }
                }
            } catch (\Throwable $e) {
                // Views and non-document collections report zeros instead of a 500.
            }
            // Missingness for the 3 main refs, derived from the same pass.
            foreach (self::MAIN_SHORTHANDS as $sh) {
                $row['missing_' . $sh] = max(0, $row['total'] - $row[$sh]);
            }
            $out[$name] = $row;
        }

        return $out;
    }

    /**
     * Resolve a merged record's reference shorthands to the titles each source
     * actually holds, so a reviewer can judge a merge without leaving the page.
     *
     * Only shorthands with a local source collection are resolved; an unknown one
     * (e.g. `t` for trakt) still comes back as a row with a null title, so the
     * reviewer sees that the record references a source the dashboard cannot show.
     *
     * @param array<string, mixed> $refs shorthand => source id
     *
     * @return list<array{shorthand: string, id: string, title: ?string, year: ?int, type: ?string}>
     */
    public function sourceTitles(array $refs): array
    {
        $db = $this->client->selectDatabase($this->database);
        $rows = [];
        foreach ($refs as $shorthand => $id) {
            $shorthand = (string) $shorthand;
            $id = is_scalar($id) ? (string) $id : '';
            $row = ['shorthand' => $shorthand, 'id' => $id, 'title' => null, 'year' => null, 'type' => null];

            $table = self::sourceTable($shorthand);
            if ($table !== null && $id !== '') {
                $doc = $db->selectCollection($table)->findOne(
                    ['reference_ids.' . $shorthand => $id],
                    ['projection' => ['title' => 1, 'year' => 1, 'type' => 1]],
                );
                if ($doc !== null) {
                    $doc = json_decode(json_encode($doc), true);
                    $title = $doc['title'] ?? null;
                    if (is_array($title)) {
                        $title = $title['romaji'] ?? $title['english'] ?? reset($title);
                    }
                    $row['title'] = is_scalar($title) ? (string) $title : null;
                    $row['year'] = isset($doc['year']) ? (int) $doc['year'] : null;
                    $row['type'] = isset($doc['type']) && is_scalar($doc['type']) ? (string) $doc['type'] : null;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The source collection a shorthand lives in, or null when the shorthand
     * has no local source.
     */
    public static function sourceTable(string $shorthand): ?string
    {
        return self::sourceTables()[$shorthand] ?? null;
    }

    /**
     * Shorthand => source collection, read from the source configuration.
     *
     * @return array<string, string>
     */
    public static function sourceTables(): array
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }

        $tables = [];
        /** @var array<string, array{table: string, shorthand: string}> $configs */
        $configs = require dirname(__DIR__) . '/Config/Sources.php';
        foreach ($configs as $config) {
            $tables[$config['shorthand']] = $config['table'];
        }

        return $tables;
    }
}
