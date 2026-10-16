<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Ml\EmbeddingService;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * The `embeddings` Mongo cache is the shared, persistent backend for
 * EmbeddingService: read-through before computing, write-through on compute,
 * keyed by `(content_hash, model, model_version)`. The JSON file cache remains
 * the default when no database is injected.
 */
final class EmbeddingCacheTest extends TestCase
{
    private const COLLECTION = 'embeddings_test';

    private Client $client;

    private Database $db;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
        $this->db = new Database(SerializerBuilder::create()->build());
    }

    protected function tearDown(): void
    {
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
    }

    public function testWriteThroughThenReadThrough(): void
    {
        $a = new EmbeddingService(32, null, '/unused.json', 'local-ngram', 'v1', $this->db, self::COLLECTION);
        $vec = $a->embed('Cowboy Bebop');
        $this->assertCount(32, $vec);

        // Write-through persisted it.
        $this->assertSame(1, $this->db->getCollection(self::COLLECTION)->countDocuments());

        // A fresh service reads the same vector back from Mongo (no recompute).
        $b = new EmbeddingService(32, null, '/unused.json', 'local-ngram', 'v1', $this->db, self::COLLECTION);
        $this->assertSame($vec, $b->embed('Cowboy Bebop'));
    }

    public function testModelAndVersionAreIsolated(): void
    {
        $a = new EmbeddingService(32, null, '/unused.json', 'm1', 'v1', $this->db, self::COLLECTION);
        $a->embed('Cowboy Bebop');

        // Different model -> cache miss -> recompute -> stored separately.
        $b = new EmbeddingService(32, null, '/unused.json', 'm2', 'v1', $this->db, self::COLLECTION);
        $b->embed('Cowboy Bebop');
        $this->assertSame(2, $this->db->getCollection(self::COLLECTION)->countDocuments());

        // Different version -> another miss.
        $c = new EmbeddingService(32, null, '/unused.json', 'm1', 'v2', $this->db, self::COLLECTION);
        $c->embed('Cowboy Bebop');
        $this->assertSame(3, $this->db->getCollection(self::COLLECTION)->countDocuments());
    }

    public function testFileCacheStillWorksWithoutDb(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'emb') . '.json';
        $a = new EmbeddingService(32, null, $file, 'local-ngram', 'v1');
        $vec = $a->embed('Cowboy Bebop');
        $a->flush();
        $this->assertFileExists($file);

        $b = new EmbeddingService(32, null, $file, 'local-ngram', 'v1');
        $this->assertSame($vec, $b->embed('Cowboy Bebop'));

        unlink($file);
    }
}
