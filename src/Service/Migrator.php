<?php

namespace Hiyori\Service;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Versioned, idempotent schema migration runner for the Hiyori dataset.
 *
 * Migrations are applied in order and recorded in the `_migrations`
 * collection; re-running is a no-op. Used by `php hiyori migrate` and as
 * part of `php hiyori setup`. The combiner refuses to run while migrations
 * are pending.
 *
 * Migration v3 (`allocate_hiyori_ids`) assigns a global, immutable
 * `hiyori_id` to every entry in `organized` / `organized_manga`. Ids are a
 * single monotonic sequence shared by both kinds, recorded in the
 * `_hiyori_ids` registry so that rebuilds and later merges re-use the
 * already-allocated id for an entry's reference set instead of shifting ids.
 * Migration v4 (`ml_fields_and_indexes`) adds the ML/label collections and the
 * dashboard indexes (`confidence`, `ml_score`, `ml_decision`, `_labels`, ...).
 */
final class Migrator
{
    /** Kind => organized collection that holds the entries. */
    public const KINDS = ['anime' => 'organized', 'manga' => 'organized_manga'];

    private const REGISTRY_COLLECTION = '_hiyori_ids';

    private const COUNTER_COLLECTION = '_counters';

    private const COUNTER_ID = 'hiyori_id';

    private const SLUG_MAX_LENGTH = 120;

    private Client $client;

    /** @var array<int, array{name: string, run: callable}> */
    private array $migrations = [];

    public function __construct(
        Database $db,
        private string $database = 'hiyori',
        private ?array $appliedStub = null
    ) {
        $this->client = $db->getClient();

        $this->migrations = [
            1 => ['name' => 'indexes_candidates_and_references', 'run' => fn () => $this->applyIndexes()],
            2 => ['name' => 'ensure_organized_collections', 'run' => fn () => $this->ensureOrganizedCollections()],
            3 => ['name' => 'allocate_hiyori_ids', 'run' => fn () => $this->allocateHiyoriIds()],
            4 => ['name' => 'ml_fields_and_indexes', 'run' => fn () => $this->mlFieldsAndIndexes()],
        ];
    }

    /** @return array<int, string> version => name */
    public function versions(): array
    {
        $out = [];
        foreach ($this->migrations as $v => $m) {
            $out[$v] = $m['name'];
        }

        return $out;
    }

    /** @return array<int, string> applied versions */
    protected function appliedVersions(): array
    {
        if ($this->appliedStub !== null) {
            return $this->appliedStub;
        }
        $applied = [];
        $cursor = $this->log()->find([], ['sort' => ['version' => 1]]);
        foreach ($cursor as $doc) {
            $applied[(int) ($doc['version'] ?? 0)] = (string) ($doc['name'] ?? '');
        }

        return $applied;
    }

    /** @return array<int, string> pending versions (in order) */
    public function pending(): array
    {
        $applied = $this->appliedVersions();
        $pending = [];
        foreach ($this->versions() as $v => $name) {
            if (!isset($applied[$v])) {
                $pending[$v] = $name;
            }
        }

        return $pending;
    }

    /** Apply all pending migrations. Returns versions applied this run. */
    public function run(?int $upto = null): array
    {
        $applied = [];
        foreach ($this->pending() as $v => $name) {
            if ($upto !== null && $v > $upto) {
                break;
            }
            ($this->migrations[$v]['run'])();
            $this->log()->updateOne(
                ['version' => $v],
                ['$set' => ['version' => $v, 'name' => $name, 'applied_at' => new \MongoDB\BSON\UTCDateTime(time() * 1000)]],
                ['upsert' => true],
            );
            $applied[$v] = $name;
        }

        return $applied;
    }

    private function log(): Collection
    {
        return $this->client->selectDatabase($this->database)->selectCollection('_migrations');
    }

    private function applyIndexes(): void
    {
        $indexes = [
            'organized' => [
                ['key' => ['type' => 1, 'status' => 1, 'year' => 1], 'name' => 'org_cand'],
                ['key' => ['title' => 1], 'name' => 'org_title'],
                ['key' => ['reference_ids.al' => 1], 'name' => 'org_ref_al'],
                ['key' => ['reference_ids.mal' => 1], 'name' => 'org_ref_mal'],
                ['key' => ['reference_ids.k' => 1], 'name' => 'org_ref_k'],
            ],
            'organized_manga' => [
                ['key' => ['type' => 1, 'status' => 1, 'year' => 1], 'name' => 'mg_cand'],
                ['key' => ['title' => 1], 'name' => 'mg_title'],
                ['key' => ['reference_ids.al_manga' => 1], 'name' => 'mg_ref_al_manga'],
                ['key' => ['reference_ids.mal_manga' => 1], 'name' => 'mg_ref_mal_manga'],
                ['key' => ['reference_ids.k_manga' => 1], 'name' => 'mg_ref_k_manga'],
            ],
            'anilist' => $this->refIndexes('al'),
            'kitsu' => $this->refIndexes('k'),
            'myanimelist' => $this->refIndexes('mal'),
            'anilist_manga' => $this->refIndexes('al_manga'),
            'kitsu_manga' => $this->refIndexes('k_manga'),
            'myanimelist_manga' => $this->refIndexes('mal_manga'),
        ];

        $db = $this->client->selectDatabase($this->database);
        foreach ($indexes as $collection => $defs) {
            $coll = $db->selectCollection($collection);
            $existing = [];
            foreach ($coll->listIndexes() as $i) {
                $existing[] = (array) $i->getKey();
            }
            foreach ($defs as $def) {
                if ($this->hasKey($existing, $def['key'])) {
                    continue; // same key already indexed (any name)
                }
                $coll->createIndex($def['key'], ['name' => $def['name']]);
            }
        }
    }

    /** @param array<int, array<string, int>> $existing */
    private function hasKey(array $existing, array $key): bool
    {
        foreach ($existing as $e) {
            if ($e === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{key: array, name: string}> */
    private function refIndexes(string $shorthand): array
    {
        return [
            ['key' => ['reference_ids.' . $shorthand => 1], 'name' => $shorthand . '_ref'],
        ];
    }

    /**
     * Migration v4: create the ML/label collections and the indexes the
     * dashboard + ML pipeline query. Additive and idempotent — existing
     * collections/indexes are left untouched.
     *
     * @return array{collections: list<string>, indexes_created: list<string>}
     */
    public function mlFieldsAndIndexes(): array
    {
        $db = $this->client->selectDatabase($this->database);
        $collections = ['_labels', 'pair_rejections', 'embeddings', 'llm_judgements'];
        foreach ($collections as $name) {
            try {
                $db->createCollection($name);
            } catch (\MongoDB\Driver\Exception\CommandException $e) {
                // already exists — fine
            }
        }

        $indexes = [
            'organized' => [
                ['key' => ['confidence' => -1], 'name' => 'org_confidence'],
                ['key' => ['ml_score' => -1], 'name' => 'org_ml_score'],
                ['key' => ['ml_decision' => 1], 'name' => 'org_ml_decision'],
            ],
            'organized_manga' => [
                ['key' => ['confidence' => -1], 'name' => 'mg_confidence'],
                ['key' => ['ml_score' => -1], 'name' => 'mg_ml_score'],
                ['key' => ['ml_decision' => 1], 'name' => 'mg_ml_decision'],
            ],
            '_labels' => [
                ['key' => ['base_source' => 1, 'base_id' => 1, 'candidate_source' => 1, 'candidate_id' => 1], 'name' => 'label_pair', 'unique' => true],
                ['key' => ['created_at' => -1], 'name' => 'created_at_desc'],
            ],
            'pair_rejections' => [
                ['key' => ['base_id' => 1, 'candidate_id' => 1], 'name' => 'pair_idx'],
                ['key' => ['created_at' => -1], 'name' => 'created_at_desc'],
            ],
            'embeddings' => [
                ['key' => ['content_hash' => 1, 'model' => 1, 'model_version' => 1], 'name' => 'content_model_version', 'unique' => true],
                ['key' => ['updated_at' => -1], 'name' => 'updated_at_desc'],
            ],
            'llm_judgements' => [
                ['key' => ['pair_hash' => 1], 'name' => 'pair_hash', 'unique' => true],
                ['key' => ['created_at' => -1], 'name' => 'created_at_desc'],
            ],
        ];

        $created = [];
        foreach ($indexes as $collection => $defs) {
            $coll = $db->selectCollection($collection);
            $existing = [];
            foreach ($coll->listIndexes() as $index) {
                $existing[] = (array) $index->getKey();
            }
            foreach ($defs as $def) {
                if ($this->hasKey($existing, $def['key'])) {
                    continue;
                }
                $options = ['name' => $def['name']];
                if (!empty($def['unique'])) {
                    $options['unique'] = true;
                }
                $coll->createIndex($def['key'], $options);
                $created[] = $collection . '.' . $def['name'];
            }
        }

        return ['collections' => $collections, 'indexes_created' => $created];
    }

    private function ensureOrganizedCollections(): void
    {
        $db = $this->client->selectDatabase($this->database);
        foreach (['organized', 'organized_manga'] as $name) {
            try {
                $db->createCollection($name);
            } catch (\MongoDB\Driver\Exception\CommandException $e) {
                // already exists — fine
            }
        }
    }

    // ------------------------------------------------------------------
    // Hiyori IDs (migration v3)
    // ------------------------------------------------------------------

    /**
     * Allocate `hiyori_id` + `slug` for every entry still missing one and
     * create the unique indexes. Idempotent: a second call finds nothing
     * missing and only re-asserts the indexes. Returns a per-kind summary.
     *
     * @return array<string, array{collection: string, allocated: int, missing: int, next_id: int}>
     */
    public function allocateHiyoriIds(): array
    {
        $summary = [];
        $refKeys = [];

        foreach (self::KINDS as $kind => $collectionName) {
            $coll = $this->collection($collectionName);
            $missing = (int) $coll->countDocuments(['hiyori_id' => ['$exists' => false]]);
            $used = $this->usedSlugs($kind);
            $allocated = 0;

            if ($missing > 0) {
                $start = $this->reserveIds($missing);
                $options = [
                    'projection' => ['title' => 1, 'reference_ids' => 1],
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                ];
                foreach ($coll->find(['hiyori_id' => ['$exists' => false]], $options) as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }
                    $id = $start + $allocated;
                    $refs = self::normalizeRefs(is_array($doc['reference_ids'] ?? null) ? $doc['reference_ids'] : []);
                    $legacy = isset($doc['_id']) ? (string) $doc['_id'] : '';
                    $slug = self::uniqueSlug(self::normalizeSlug(self::titleOf($doc['title'] ?? '')), $used);
                    $used[$slug] = true;
                    foreach (array_keys($refs) as $key) {
                        $refKeys[$key] = true;
                    }

                    $this->registry()->updateOne(
                        ['hiyori_id' => $id],
                        ['$setOnInsert' => [
                            'hiyori_id' => $id,
                            'kind' => $kind,
                            'refs' => (object) $refs,
                            'slug' => $slug,
                            'legacy_id' => $legacy,
                            'allocated_at' => new UTCDateTime(),
                        ]],
                        ['upsert' => true],
                    );
                    $coll->updateOne(['_id' => $doc['_id']], ['$set' => ['hiyori_id' => $id, 'slug' => $slug]]);
                    $allocated++;
                }
            }

            $duplicates = $this->duplicateIds($collectionName);
            if ($duplicates !== []) {
                throw new \RuntimeException(sprintf(
                    'allocate_hiyori_ids: duplicate hiyori_id in %s: %s',
                    $collectionName,
                    json_encode($duplicates),
                ));
            }

            $this->ensureHiyoriIdIndex($collectionName);

            $summary[$kind] = [
                'collection' => $collectionName,
                'allocated' => $allocated,
                'missing' => $missing,
                'next_id' => $this->counterNext(),
            ];
        }

        $this->ensureRegistryIndexes(array_keys($refKeys));

        return $summary;
    }

    /**
     * Allocate (or recover) the stable id for a single entry.
     *
     * Returns the id already recorded for this reference set / legacy id when
     * one exists — a later merge of another source INTO the entry therefore
     * reuses the id — otherwise reserves the next id and records it.
     *
     * @param array<string, mixed> $refs source shorthand => source id
     */
    public function allocateId(string $kind, array $refs, string $slug, string $legacyId = ''): int
    {
        $this->assertKind($kind);
        $refs = self::normalizeRefs($refs);

        $existing = $this->findRegistryId($kind, $refs, $legacyId);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->reserveIds(1);
        $slug = self::uniqueSlug(self::normalizeSlug($slug), $this->usedSlugs($kind));

        $this->registry()->updateOne(
            ['hiyori_id' => $id],
            ['$setOnInsert' => [
                'hiyori_id' => $id,
                'kind' => $kind,
                'refs' => (object) $refs,
                'slug' => $slug,
                'legacy_id' => $legacyId,
                'allocated_at' => new UTCDateTime(),
            ]],
            ['upsert' => true],
        );

        return $id;
    }

    /**
     * @return array<string, mixed> counts for the `hiyori-id` report
     */
    public function hiyoriIdReport(): array
    {
        $collections = [];
        $duplicateTotal = 0;

        foreach (self::KINDS as $kind => $collectionName) {
            $coll = $this->collection($collectionName);
            $total = (int) $coll->countDocuments();
            $allocated = (int) $coll->countDocuments(['hiyori_id' => ['$exists' => true]]);
            $duplicates = $this->duplicateIds($collectionName);
            $duplicateTotal += count($duplicates);
            $collections[$kind] = [
                'collection' => $collectionName,
                'total' => $total,
                'allocated' => $allocated,
                'missing' => $total - $allocated,
                'duplicates' => count($duplicates),
            ];
        }

        return [
            'collections' => $collections,
            'next_id' => $this->counterNext(),
            'registry_size' => (int) $this->registry()->countDocuments(),
            'duplicate_count' => $duplicateTotal,
        ];
    }

    /**
     * Integrity check across docs + registry. Returns up to $maxProblems
     * human-readable problems; `ok` is false when any were found.
     *
     * @return array{ok: bool, problems: array<int, string>, checked: array<string, int>}
     */
    public function verifyHiyoriIds(int $maxProblems = 20): array
    {
        $problems = [];
        $add = function (string $problem) use (&$problems, $maxProblems): void {
            if (count($problems) < $maxProblems) {
                $problems[] = $problem;
            }
        };

        $registryKind = [];
        $options = ['projection' => ['hiyori_id' => 1, 'kind' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']];
        foreach ($this->registry()->find([], $options) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $registryKind[(int) ($row['hiyori_id'] ?? 0)] = (string) ($row['kind'] ?? '');
        }

        $checked = ['registry' => count($registryKind)];

        foreach (self::KINDS as $kind => $collectionName) {
            $coll = $this->collection($collectionName);
            $docs = 0;
            $missing = 0;
            $seenIds = [];
            $seenSlugs = [];
            $findOptions = ['projection' => ['hiyori_id' => 1, 'slug' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']];

            foreach ($coll->find([], $findOptions) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $docs++;

                if (!array_key_exists('hiyori_id', $row) || $row['hiyori_id'] === null) {
                    $missing++;
                    $add(sprintf('%s: document missing hiyori_id', $collectionName));
                    continue;
                }

                $id = (int) $row['hiyori_id'];
                if (isset($seenIds[$id])) {
                    $add(sprintf('%s: duplicate hiyori_id %d', $collectionName, $id));
                }
                $seenIds[$id] = true;

                $slug = $row['slug'] ?? null;
                if (!is_string($slug) || $slug === '') {
                    $add(sprintf('%s: id %d has no slug', $collectionName, $id));
                } elseif (isset($seenSlugs[$slug])) {
                    $add(sprintf('%s: duplicate slug "%s"', $collectionName, $slug));
                } else {
                    $seenSlugs[$slug] = $id;
                }

                if (!isset($registryKind[$id])) {
                    $add(sprintf('%s: doc id %d absent from registry', $collectionName, $id));
                } elseif ($registryKind[$id] !== $kind) {
                    $add(sprintf('%s: doc id %d registry kind "%s" != "%s"', $collectionName, $id, $registryKind[$id], $kind));
                }
            }

            foreach ($registryKind as $id => $registryEntryKind) {
                if ($registryEntryKind === $kind && !isset($seenIds[$id])) {
                    $add(sprintf('%s: registry id %d has no document', $collectionName, $id));
                }
            }

            $checked[$kind] = $docs;
            $checked[$kind . '_missing'] = $missing;
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'checked' => $checked];
    }

    /** Trim/slugify a title; shared with the command and the export side. */
    public static function normalizeSlug(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));

        if ($slug === '') {
            $slug = 'untitled';
        }

        return substr($slug, 0, self::SLUG_MAX_LENGTH);
    }

    /**
     * Return a slug that is not present in `$used`, suffixed with `-2`,
     * `-3`, … on collision.
     *
     * @param array<string, true> $used
     */
    public static function uniqueSlug(string $base, array $used): string
    {
        if (!isset($used[$base])) {
            return $base;
        }

        $suffix = 2;
        while (isset($used[$base . '-' . $suffix])) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    /**
     * Pure lookup used by {@see allocateId()}: pick the id already recorded for
     * this kind when the entry shares a reference or the legacy id.
     *
     * @param array<int, array<string, mixed>> $rows registry rows
     * @param array<string, string> $refs
     */
    public static function matchKnownId(array $rows, string $kind, array $refs, string $legacyId): ?int
    {
        $matches = [];

        foreach ($rows as $row) {
            if (($row['kind'] ?? null) !== $kind) {
                continue;
            }

            $hit = $legacyId !== '' && (string) ($row['legacy_id'] ?? '') === $legacyId;

            if (!$hit && is_array($row['refs'] ?? null)) {
                foreach ($refs as $source => $id) {
                    if (isset($row['refs'][$source]) && (string) $row['refs'][$source] === (string) $id) {
                        $hit = true;
                        break;
                    }
                }
            }

            if ($hit) {
                $matches[] = (int) ($row['hiyori_id'] ?? 0);
            }
        }

        if ($matches === []) {
            return null;
        }

        return min($matches);
    }

    /**
     * Normalize a `reference_ids` map: drop empty/nested values, stringify,
     * sort for a deterministic key order.
     *
     * @param array<mixed> $refs
     * @return array<string, string>
     */
    public static function normalizeRefs(array $refs): array
    {
        $out = [];
        foreach ($refs as $source => $id) {
            if (!is_string($source) || $source === '' || is_array($id)) {
                continue;
            }
            $value = (string) $id;
            if ($value === '') {
                continue;
            }
            $out[$source] = $value;
        }
        ksort($out);

        return $out;
    }

    /** First scalar string in a title (titles can be a string or a map/list). */
    public static function titleOf(mixed $title): string
    {
        if (is_string($title)) {
            return $title;
        }
        if (is_scalar($title)) {
            return (string) $title;
        }
        if (is_array($title)) {
            foreach ($title as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    /** @return array<string, true> slugs already recorded for a kind */
    private function usedSlugs(string $kind): array
    {
        $used = [];
        $options = ['projection' => ['slug' => 1, '_id' => 0], 'typeMap' => ['root' => 'array', 'document' => 'array']];
        foreach ($this->registry()->find(['kind' => $kind], $options) as $row) {
            if (is_array($row) && isset($row['slug'])) {
                $used[(string) $row['slug']] = true;
            }
        }

        return $used;
    }

    /**
     * @param array<string, string> $refs
     */
    private function findRegistryId(string $kind, array $refs, string $legacyId): ?int
    {
        $or = [];
        foreach ($refs as $source => $id) {
            $or[] = ['refs.' . $source => $id];
        }
        if ($legacyId !== '') {
            $or[] = ['legacy_id' => $legacyId];
        }
        if ($or === []) {
            return null;
        }

        $rows = [];
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        foreach ($this->registry()->find(['kind' => $kind, '$or' => $or], $options) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return self::matchKnownId($rows, $kind, $refs, $legacyId);
    }

    /** Reserve $count consecutive ids; returns the first of the block. */
    private function reserveIds(int $count): int
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('reserveIds expects a positive count');
        }

        $this->ensureCounter();

        $doc = $this->counters()->findOneAndUpdate(
            ['_id' => self::COUNTER_ID],
            ['$inc' => ['next' => $count]],
            [
                'upsert' => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ],
        );

        $next = is_array($doc) ? (int) ($doc['next'] ?? $count) : $count;

        return $next - $count;
    }

    /** Seed the counter from the registry high-water mark if it is missing. */
    private function ensureCounter(): void
    {
        $this->counters()->updateOne(
            ['_id' => self::COUNTER_ID],
            ['$setOnInsert' => ['next' => $this->maxRegistryId() + 1]],
            ['upsert' => true],
        );
    }

    private function maxRegistryId(): int
    {
        $doc = $this->registry()->findOne(
            [],
            ['sort' => ['hiyori_id' => -1], 'projection' => ['hiyori_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']],
        );

        return is_array($doc) ? (int) ($doc['hiyori_id'] ?? 0) : 0;
    }

    /** Next id the counter will hand out (does not reserve it). */
    private function counterNext(): int
    {
        $doc = $this->counters()->findOne(
            ['_id' => self::COUNTER_ID],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        );

        if (!is_array($doc)) {
            return $this->maxRegistryId() + 1;
        }

        return (int) ($doc['next'] ?? 1);
    }

    /**
     * @return array<int, int> duplicated hiyori_id values in the collection
     */
    private function duplicateIds(string $collectionName): array
    {
        $pipeline = [
            ['$match' => ['hiyori_id' => ['$exists' => true]]],
            ['$group' => ['_id' => '$hiyori_id', 'count' => ['$sum' => 1]]],
            ['$match' => ['count' => ['$gt' => 1]]],
        ];

        $duplicates = [];
        $cursor = $this->collection($collectionName)->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        foreach ($cursor as $row) {
            if (is_array($row)) {
                $duplicates[] = (int) $row['_id'];
            }
        }

        return $duplicates;
    }

    private function ensureHiyoriIdIndex(string $collectionName): void
    {
        $this->collection($collectionName)->createIndex(['hiyori_id' => 1], ['name' => 'hiyori_id_1', 'unique' => true]);
    }

    /** @param array<int, string> $refKeys */
    private function ensureRegistryIndexes(array $refKeys = []): void
    {
        $registry = $this->registry();
        $registry->createIndex(['hiyori_id' => 1], ['name' => 'hiyori_id_1', 'unique' => true]);
        $registry->createIndex(['kind' => 1, 'slug' => 1], ['name' => 'kind_slug_1', 'unique' => true]);

        foreach ($refKeys as $key) {
            if ($key === '') {
                continue;
            }
            $registry->createIndex(['refs.' . $key => 1], ['name' => 'refs_' . $key . '_1']);
        }
    }

    private function registry(): Collection
    {
        return $this->collection(self::REGISTRY_COLLECTION);
    }

    private function counters(): Collection
    {
        return $this->collection(self::COUNTER_COLLECTION);
    }

    private function collection(string $name): Collection
    {
        return $this->client->selectDatabase($this->database)->selectCollection($name);
    }

    private function assertKind(string $kind): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException(sprintf("Unknown kind '%s' (expected anime|manga)", $kind));
        }
    }
}
