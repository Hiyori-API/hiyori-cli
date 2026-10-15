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
    /**
     * Reference keys that identify a single work. Franchise/social/streaming
     * keys (`k`, `cr`, `t`, `tx`, `nf`, ...) legitimately repeat across many
     * entries and must never be used to decide two documents are the same
     * work — doing so handed unrelated entries the same `hiyori_id`.
     */
    public const IDENTITY_REF_KEYS = ['mal', 'al', 'mal_manga', 'al_manga'];

    /**
     * Base ingest collection + its primary reference key for each combined
     * collection. Used to decide which of two documents genuinely owns an
     * identity key (the one whose base row declares it).
     */
    public const BASE_SOURCE = [
        'organized' => ['collection' => 'anilist', 'key' => 'al'],
        'organized_manga' => ['collection' => 'anilist_manga', 'key' => 'al_manga'],
    ];

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

            // Repair slugs for documents that already carry an id. The combiner
            // stamps `hiyori_id` during a merge but deliberately leaves `slug` to
            // this backfill, so the id registry stays the single source of truth
            // for unique slugs (see verifyHiyoriIds()). Safe to re-run.
            $slugsFixed = 0;
            foreach ($coll->find(
                [
                    'hiyori_id' => ['$exists' => true],
                    '$or' => [['slug' => ['$exists' => false]], ['slug' => '']],
                ],
                ['projection' => ['hiyori_id' => 1, 'slug' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']],
            ) as $doc) {
                if (!is_array($doc)) {
                    continue;
                }

                $docId = (int) ($doc['hiyori_id'] ?? 0);
                if ($docId <= 0) {
                    continue;
                }

                $registryRow = $this->registry()->findOne(
                    ['hiyori_id' => $docId],
                    ['projection' => ['slug' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']],
                );
                $registrySlug = is_array($registryRow) ? (string) ($registryRow['slug'] ?? '') : '';
                if ($registrySlug === '') {
                    continue;
                }

                $coll->updateOne(['_id' => $doc['_id']], ['$set' => ['slug' => $registrySlug]]);
                $slugsFixed++;
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
                'slugs_fixed' => $slugsFixed,
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

    /**
     * Resolve duplicate identity keys in place: for every primary key value
     * held by more than one document, keep it on the genuine owner and
     * remove it from the others.
     *
     * This is the release-gate unblocker for collisions that survive precise
     * matching (e.g. the same `mal` value assigned to two source rows during
     * ingestion) and never deletes documents, so coverage and ids are stable.
     *
     * @param list<string> $primaryKeys
     *
     * @return array{collection: string, primary_keys: list<string>, duplicate_keys: int, resolved: int, dry_run: bool, keys: list<array{source: string, key: string, keep: int, dropped: list<int>}>}
     */
    public function stripDuplicateIdentityKeys(string $collectionName, array $primaryKeys = ['mal', 'al'], bool $dryRun = false, int $maxKeys = 50, ?string $baseCollection = null, ?string $baseKey = null): array
    {
        $collection = $this->collection($collectionName);
        $primary = array_fill_keys(array_map('strtolower', $primaryKeys), true);

        // Cache of base-row reference ids keyed by the document's base value,
        // so the corroboration lookup runs once per work, not per key.
        /** @var array<string, array<string, string>> $baseRefCache */
        $baseRefCache = [];
        $baseRefs = function (string $baseValue) use (&$baseRefCache, $baseCollection, $baseKey): array {
            if ($baseValue === '' || $baseCollection === null || $baseKey === null) {
                return [];
            }
            if (isset($baseRefCache[$baseValue])) {
                return $baseRefCache[$baseValue];
            }

            $row = $this->collection($baseCollection)->findOne(
                ['reference_ids.' . $baseKey => $baseValue],
                ['projection' => ['reference_ids' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']],
            );
            $refs = [];
            if (is_array($row) && is_array($row['reference_ids'] ?? null)) {
                foreach ($row['reference_ids'] as $key => $value) {
                    $refs[strtolower((string) $key)] = (string) $value;
                }
            }

            return $baseRefCache[$baseValue] = $refs;
        };

        /** @var array<string, array<string, list<array{doc_id: mixed, hiyori_id: int, base_value: string}>>> $holders */
        $holders = [];
        $options = [
            'projection' => ['hiyori_id' => 1, 'reference_ids' => 1],
            'sort' => ['hiyori_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        foreach ($collection->find([], $options) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $refs = $row['reference_ids'] ?? null;
            if (is_object($refs)) {
                $refs = $refs instanceof \Traversable ? iterator_to_array($refs) : get_object_vars($refs);
            }
            if (!is_array($refs)) {
                continue;
            }

            $id = (int) ($row['hiyori_id'] ?? 0);
            $baseValue = '';
            if ($baseKey !== null) {
                foreach ($refs as $refKey => $refValue) {
                    if (strtolower((string) $refKey) === strtolower($baseKey)) {
                        $baseValue = (string) $refValue;
                        break;
                    }
                }
            }

            foreach ($refs as $source => $value) {
                $source = strtolower((string) $source);
                if (!isset($primary[$source]) || !is_string($value) || $value === '') {
                    continue;
                }
                $holders[$source][$value][] = ['doc_id' => $row['_id'], 'hiyori_id' => $id, 'base_value' => $baseValue];
            }
        }

        $resolved = 0;
        $keys = [];
        foreach ($holders as $source => $values) {
            foreach ($values as $value => $rows) {
                if (count($rows) < 2) {
                    continue;
                }

                // Prefer the document whose base row declares this identity
                // key; otherwise fall back to the smallest hiyori_id. A false
                // merge usually leaves the key on a document whose base row
                // never mentions it, while the genuine owner's base row does.
                usort($rows, function (array $a, array $b) use ($baseRefs, $source, $value): int {
                    $corrA = ($baseRefs($a['base_value'])[$source] ?? null) === (string) $value;
                    $corrB = ($baseRefs($b['base_value'])[$source] ?? null) === (string) $value;
                    if ($corrA !== $corrB) {
                        return $corrA ? -1 : 1;
                    }

                    return $a['hiyori_id'] <=> $b['hiyori_id'];
                });
                $keep = array_shift($rows);
                $dropped = [];
                foreach ($rows as $row) {
                    $dropped[] = $row['hiyori_id'];
                    if (!$dryRun) {
                        $collection->updateOne(['_id' => $row['doc_id']], ['$unset' => ['reference_ids.' . $source => '']]);
                    }
                }

                $resolved++;
                if (count($keys) < $maxKeys) {
                    $keys[] = [
                        'source' => $source,
                        'key' => (string) $value,
                        'keep' => $keep['hiyori_id'],
                        'dropped' => $dropped,
                    ];
                }
            }
        }

        return [
            'collection' => $collectionName,
            'primary_keys' => array_keys($primary),
            'duplicate_keys' => $resolved,
            'resolved' => $resolved,
            'dry_run' => $dryRun,
            'keys' => $keys,
        ];
    }

    /**
     * Count duplicate identity keys across a combined collection.
     *
     * A duplicate is two or more documents sharing the same value for a
     * `reference_ids.<source>` shorthand. Identity keys (`mal`, `al`) must be
     * unique; franchise keys (`k`, `cr`, `t`, ...) legitimately repeat.
     *
     * Note on `references` shape: the field is written by `AnimeBase::merge()`
     * as `array_unique()` of a URL list. When dedup removes an entry the list
     * keeps its gaps and serializes to a BSON document, otherwise it stays a
     * BSON array. Both are current combiner output; the shape is a
     * serialization artefact, not a schema generation, so it is not used to
     * classify duplicates.
     *
     * @param list<string> $primaryKeys
     *
     * @return array{collection: string, docs: int, references_array_docs: int, primary_keys: list<string>, primary_duplicates: int, sources: array<string, array{values: int, duplicates: int, max: int, primary: bool, examples: list<array{key: string, hiyori_ids: list<int>}>}>}
     */
    public function duplicateReferenceKeys(string $collectionName, array $primaryKeys = ['mal', 'al'], int $maxExamples = 3): array
    {
        $collection = $this->collection($collectionName);
        $docs = $collection->countDocuments([]);
        $arrayShaped = $collection->countDocuments(['references' => ['$type' => 'array']]);

        $options = ['projection' => ['hiyori_id' => 1, 'reference_ids' => 1], 'typeMap' => ['root' => 'array']];

        /** @var array<string, array<string, list<int>>> $bySource */
        $bySource = [];
        foreach ($collection->find([], $options) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $refs = $row['reference_ids'] ?? null;
            if (is_object($refs)) {
                $refs = $refs instanceof \Traversable ? iterator_to_array($refs) : get_object_vars($refs);
            }
            if (!is_array($refs)) {
                continue;
            }

            $id = (int) ($row['hiyori_id'] ?? 0);
            foreach ($refs as $source => $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $bySource[(string) $source][$value][] = $id;
            }
        }

        $primary = array_fill_keys(array_map('strtolower', $primaryKeys), true);

        $sources = [];
        $primaryDuplicates = 0;
        foreach ($bySource as $source => $values) {
            $duplicates = 0;
            $max = 1;
            $examples = [];
            foreach ($values as $key => $holders) {
                $count = count($holders);
                if ($count < 2) {
                    continue;
                }
                $duplicates++;
                $max = max($max, $count);
                if (count($examples) < $maxExamples) {
                    $examples[] = [
                        'key' => (string) $key,
                        'hiyori_ids' => array_slice($holders, 0, 8),
                    ];
                }
            }
            $isPrimary = isset($primary[$source]);
            $sources[$source] = [
                'values' => count($values),
                'duplicates' => $duplicates,
                'max' => $max,
                'primary' => $isPrimary,
                'examples' => $examples,
            ];
            if ($isPrimary) {
                $primaryDuplicates += $duplicates;
            }
        }
        ksort($sources);

        return [
            'collection' => $collectionName,
            'docs' => $docs,
            'references_array_docs' => $arrayShaped,
            'primary_keys' => array_keys($primary),
            'primary_duplicates' => $primaryDuplicates,
            'sources' => $sources,
        ];
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
                // Compare identity keys only, and require the registry row to
                // agree on every identity key the two share. A row can carry a
                // foreign identity key when an earlier run merged two works
                // (e.g. one row holding both Beelzebub's `al` and an unrelated
                // `mal`); a conflict must veto the match, otherwise the plain
                // `min()` tie-break hands the two works the same id.
                $rowRefs = [];
                foreach ($row['refs'] as $key => $value) {
                    $rowRefs[strtolower((string) $key)] = (string) $value;
                }

                $agrees = false;
                $conflicts = false;
                foreach ($refs as $source => $id) {
                    $key = strtolower((string) $source);
                    if (!in_array($key, self::IDENTITY_REF_KEYS, true) || !isset($rowRefs[$key])) {
                        continue;
                    }
                    if ($rowRefs[$key] === (string) $id) {
                        $agrees = true;
                    } else {
                        $conflicts = true;
                    }
                }

                if ($agrees && !$conflicts) {
                    $hit = true;
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
