<?php

namespace Hiyori\Service;

use MongoDB\Client;
use MongoDB\Collection;

/**
 * Versioned, idempotent schema migration runner for the Hiyori dataset.
 *
 * Migrations are applied in order and recorded in the `_migrations`
 * collection; re-running is a no-op. Used by `php hiyori migrate` and as
 * part of `php hiyori setup`. The combiner refuses to run while migrations
 * are pending.
 */
final class Migrator
{
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
}
