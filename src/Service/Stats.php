<?php

declare(strict_types=1);

namespace Hiyori\Service;

use MongoDB\Client as MongoClient;

/**
 * Read-only size and count statistics over the live database.
 *
 * The dashboard asks the same two questions in three places ("how many
 * documents does each collection hold", "how big is the database"), and the
 * answers were two free functions in the front controller that each walked
 * the collection list again. This service owns both reads so the HTTP layer
 * only decides *when* to ask.
 */
final class Stats
{
    /** The one database this application owns. */
    public const DATABASE = 'hiyori';

    private MongoClient $client;

    private string $database;

    public function __construct(MongoClient $client, string $database = self::DATABASE)
    {
        $this->client = $client;
        $this->database = $database;
    }

    /**
     * Document count per collection.
     *
     * @return array<string, int>
     */
    public function collectionCounts(): array
    {
        $out = [];
        $namespace = $this->client->selectDatabase($this->database);
        foreach ($namespace->listCollections() as $collection) {
            $name = $collection->getName();
            $out[$name] = $namespace->selectCollection($name)->countDocuments();
        }

        return $out;
    }

    /**
     * Database size plus the per-collection counts.
     *
     * @param array<string, int>|null $counts Reuse the caller's counts when it
     *                                        already listed them — this
     *                                        endpoint and /api/status would
     *                                        otherwise walk every collection
     *                                        twice.
     *
     * @return array{dataSize: int, indexSize: int, storageSize: int, collections: array<string, int>, totalDocuments: int}
     */
    public function dbStats(?array $counts = null): array
    {
        $db = $this->client->selectDatabase($this->database);
        $stats = $db->command(['dbstats' => 1])->toArray()[0] ?? [];
        $counts ??= $this->collectionCounts();

        return [
            'dataSize' => (int) ($stats['dataSize'] ?? 0),
            'indexSize' => (int) ($stats['indexSize'] ?? 0),
            'storageSize' => (int) ($stats['storageSize'] ?? 0),
            'collections' => $counts,
            'totalDocuments' => array_sum($counts),
        ];
    }
}
