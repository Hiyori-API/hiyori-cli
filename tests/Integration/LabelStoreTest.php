<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Ml\LabelStore;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class LabelStoreTest extends TestCase
{
    private const COLLECTION = '_labels_test';

    private Client $client;

    private LabelStore $store;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
        $this->store = LabelStore::create(new Database(SerializerBuilder::create()->build()), self::COLLECTION);
    }

    protected function tearDown(): void
    {
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
    }

    public function testUpsertIsIdempotentPerPairAndUpdatesTheVote(): void
    {
        $this->assertTrue($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => 'al',
            'candidate_id' => '2',
            'label' => 1,
        ]));
        $this->assertSame(1, $this->store->count(1));

        // Same pair, flipped vote — updates in place, no duplicate row.
        $this->assertTrue($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => 'al',
            'candidate_id' => '2',
            'label' => 0,
            'notes' => 'different seasons',
        ]));
        $this->assertSame(1, $this->store->count());
        $this->assertSame(0, $this->store->count(1));
        $this->assertSame(1, $this->store->count(0));
    }

    public function testUpsertRejectsIncompletePairs(): void
    {
        $this->assertFalse($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => '',
            'candidate_id' => '2',
            'label' => 1,
        ]));
        $this->assertSame(0, $this->store->count());
    }

    public function testForBaseAndStats(): void
    {
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '10', 'candidate_source' => 'al', 'candidate_id' => '20', 'label' => 1]);
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '10', 'candidate_source' => 'k', 'candidate_id' => '30', 'label' => 0]);
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '11', 'candidate_source' => 'al', 'candidate_id' => '21', 'label' => 1, 'source' => 'llm']);

        $labels = $this->store->forBase('mal', '10');
        $this->assertSame(['al:20' => 1, 'k:30' => 0], $labels);

        $stats = $this->store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['positives']);
        $this->assertSame(1, $stats['negatives']);
        $this->assertSame(2, $stats['by_source']['human'] ?? 0);
        $this->assertSame(1, $stats['by_source']['llm'] ?? 0);
    }
}
