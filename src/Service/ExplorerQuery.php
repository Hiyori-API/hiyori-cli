<?php

declare(strict_types=1);

namespace Hiyori\Service;

use MongoDB\Client as MongoClient;
use MongoDB\Collection;

/**
 * The database-aware half of the dashboard's Explorer.
 *
 * {@see Explorer} holds the pure rules — how a filter is built, which fields
 * are safe, how a facet pipeline looks, how a CSV is parsed — and stays
 * free of any database so it can be unit-tested. This service is the part
 * that actually talks to MongoDB: it runs the queries, resolves the
 * collection a route parameter names and answers the questions the Explorer
 * pages ask.
 */
final class ExplorerQuery
{
    private MongoClient $client;

    private string $database;

    public function __construct(MongoClient $client, ?string $database = null)
    {
        $this->client = $client;
        $this->database = $database ?? Stats::DATABASE;
    }

    /**
     * The collection a route parameter names.
     *
     * The name is expected to be validated already (Explorer::collectionName);
     * this only resolves it.
     */
    public function collection(string $name): Collection
    {
        return $this->client->selectDatabase($this->database)->selectCollection($name);
    }

    /**
     * The Explorer list query: the filtered page of documents, the fields the
     * page shows and the optional facet chips.
     *
     * @param array<string, mixed> $q
     *
     * @return array<string, mixed>
     */
    public function explore(array $q): array
    {
        $collection = Explorer::collectionName((string) ($q['collection'] ?? 'anilist'));
        if ($collection === '') {
            return ['error' => 'bad collection'];
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $per = min(100, max(1, (int) ($q['per'] ?? 25)));
        $sort = (string) ($q['sort'] ?? '');
        $dir = (strtolower((string) ($q['dir'] ?? 'asc')) === 'desc') ? -1 : 1;

        // ?regex=1 → keep the search term as a raw PCRE pattern (frontend regex
        // toggle); invalid/oversized patterns fall back to escaped-literal matching.
        $regex = (($q['regex'] ?? '') === '1');

        $coll = $this->collection($collection);
        $mongoFilter = Explorer::buildFilter($q, $regex);

        $total = $coll->countDocuments($mongoFilter);
        $opts = ['skip' => ($page - 1) * $per, 'limit' => $per];
        if ($sort !== '' && Explorer::fieldPath($sort) !== '') {
            $opts['sort'] = [$sort => $dir];
        }
        $rows = [];
        foreach ($coll->find($mongoFilter, $opts) as $doc) {
            $rows[] = json_decode(json_encode($doc), true);
        }
        $fields = $rows !== [] ? array_keys($rows[0]) : [];

        $out = [
            'collection' => $collection,
            'regex' => $regex,
            'page' => $page,
            'per' => $per,
            'total' => $total,
            'docs' => $rows,
            'fields' => $fields,
        ];

        // Optional distinct-value facets: ?facets=format,status → one aggregation
        // per field, top 12 string values, empty values skipped.
        $facetsParam = trim((string) ($q['facets'] ?? ''));
        if ($facetsParam !== '') {
            $facets = [];
            foreach (array_unique(array_filter(array_map('trim', explode(',', $facetsParam)))) as $f) {
                if (Explorer::fieldPath($f) === '') {
                    continue;
                }
                $facets[$f] = $this->facetValues($coll, $f, $mongoFilter);
            }
            $out['facets'] = $facets;
        }

        return $out;
    }

    /**
     * The distinct values of one field, for a facet chip.
     *
     * @param array<string, mixed> $mongoFilter
     *
     * @return list<array{v: string, n: int}>
     */
    public function facetValues(Collection $coll, string $field, array $mongoFilter): array
    {
        $out = [];
        try {
            foreach ($coll->aggregate(Explorer::facetPipeline($field, $mongoFilter)) as $r) {
                $v = $r['_id'] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $out[] = ['v' => (string) $v, 'n' => (int) ($r['n'] ?? 0)];
            }
        } catch (\Throwable $e) {
            // field may not exist on this collection — return an empty facet
        }

        return $out;
    }

    /**
     * The 10-bin score/confidence histogram (bin width 10 over 0..100).
     *
     * @return array<int, int>
     */
    public function histogram(string $collection = 'organized', ?string $field = null): array
    {
        $db = $this->client->selectDatabase($this->database);
        $bins = [];
        $fields = $field !== null ? [$field] : ['score', 'confidence'];
        foreach ($fields as $name) {
            try {
                $pipeline = [
                    ['$match' => [$name => ['$exists' => true]]],
                    ['$group' => ['_id' => ['$floor' => ['$multiply' => [['$divide' => ['$' . $name, 10]], 1.0]]], 'n' => ['$sum' => 1]]],
                ];
                foreach ($db->selectCollection($collection)->aggregate($pipeline)->toArray() as $r) {
                    $bin = (int) ($r['_id'] ?? -1);
                    if ($bin >= 0 && $bin <= 10) {
                        $bins[min(9, $bin) * 10] = (int) ($bins[min(9, $bin) * 10] ?? 0) + (int) ($r['n'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                // fall through to next field
            }
        }

        $out = [];
        for ($i = 0; $i < 10; ++$i) {
            $out[$i * 10] = $bins[$i * 10] ?? 0;
        }

        return $out;
    }

    /**
     * How many documents carry each of the given fields.
     *
     * @param list<string> $fields
     *
     * @return array<string, int>
     */
    public function metaMissingCounts(Collection $coll, array $fields): array
    {
        $fields = array_values(array_filter(array_slice($fields, 0, 24), static fn ($f): bool => Explorer::fieldPath((string) $f) !== ''));
        if ($fields === []) {
            return [];
        }
        $group = ['_id' => null];
        foreach ($fields as $f) {
            $group['n_' . str_replace('.', '__', $f)] = [
                '$sum' => ['$cond' => [['$eq' => [['$ifNull' => ['$' . $f, null]], null]], 1, 0]],
            ];
        }
        $row = $coll->aggregate([['$group' => $group]])->toArray()[0] ?? null;
        if ($row === null) {
            return [];
        }
        $missing = [];
        foreach ($fields as $f) {
            $key = 'n_' . str_replace('.', '__', $f);
            $missing[$f] = (int) ($row[$key] ?? 0);
        }

        return $missing;
    }
}
