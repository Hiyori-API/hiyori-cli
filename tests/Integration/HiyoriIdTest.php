<?php

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class HiyoriIdTest extends TestCase
{
    private const DB = 'hiyori_test_hiyori_id';

    private Client $client;

    protected function setUp(): void
    {
        $_ENV['MONGODB_URL'] ??= $_SERVER['MONGODB_URL'] ?? 'mongodb://localhost:27017';
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->selectDatabase(self::DB)->drop();
    }

    protected function tearDown(): void
    {
        $this->client->selectDatabase(self::DB)->drop();
    }

    public function testMigrationAllocatesIdsSequentiallyAndIsIdempotent(): void
    {
        $db = new Database(SerializerBuilder::create()->build());

        $this->client->selectDatabase(self::DB)->selectCollection('organized')->insertMany([
            ['title' => 'Cowboy Bebop', 'reference_ids' => ['al' => '1'], 'type' => 'tv'],
            ['title' => 'Cowboy Bebop', 'reference_ids' => ['al' => '2'], 'type' => 'tv'],
            ['title' => 'Trigun', 'reference_ids' => ['mal' => '5'], 'type' => 'tv'],
        ]);
        $this->client->selectDatabase(self::DB)->selectCollection('organized_manga')->insertMany([
            ['title' => 'Monster', 'reference_ids' => ['al_manga' => '19'], 'type' => 'manga'],
            ['title' => 'Bleach', 'reference_ids' => ['mal_manga' => '6'], 'type' => 'manga'],
        ]);

        $migrator = new Migrator($db, self::DB);
        $applied = $migrator->run();
        $this->assertArrayHasKey(3, $applied);

        $anime = $this->rows('organized');
        $manga = $this->rows('organized_manga');

        // Global sequence: anime 1..3, manga continues 4..5 (not per-kind).
        $this->assertSame([1, 2, 3], $this->idsOf($anime));
        $this->assertSame([4, 5], $this->idsOf($manga));

        // Slugs are per-kind and collision-suffixed.
        $this->assertSame(['cowboy-bebop', 'cowboy-bebop-2', 'trigun'], $this->slugsOf($anime));
        $this->assertSame(['monster', 'bleach'], $this->slugsOf($manga));

        // Unique index exists on hiyori_id in both collections.
        foreach (['organized', 'organized_manga'] as $name) {
            $index = null;
            foreach ($this->client->selectDatabase(self::DB)->selectCollection($name)->listIndexes() as $i) {
                if ($i->getName() === 'hiyori_id_1') {
                    $index = $i;
                }
            }
            $this->assertNotNull($index, "missing hiyori_id_1 on $name");
            $this->assertTrue($index->isUnique());
        }

        // Registry rows match the documents.
        $registry = $this->client->selectDatabase(self::DB)->selectCollection('_hiyori_ids')
            ->find([], ['sort' => ['hiyori_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);
        $rows = [];
        foreach ($registry as $row) {
            $rows[] = $row;
        }
        $this->assertCount(5, $rows);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn ($r) => (int) $r['hiyori_id'], $rows));
        $this->assertSame(['anime', 'anime', 'anime', 'manga', 'manga'], array_map(static fn ($r) => $r['kind'], $rows));
        $this->assertTrue($rows[0]['allocated_at'] instanceof UTCDateTime);
        $this->assertSame(['al' => '1'], $rows[0]['refs']);
        $this->assertSame('cowboy-bebop', $rows[0]['slug']);
        $this->assertNotSame('', (string) $rows[0]['legacy_id']);

        // Counter points past the highest allocated id.
        $counter = $this->client->selectDatabase(self::DB)->selectCollection('_counters')
            ->findOne(['_id' => 'hiyori_id'], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $this->assertSame(6, (int) $counter['next']);

        // Re-running the migration changes nothing.
        $secondRun = $migrator->run();
        $this->assertSame([], $secondRun);
        $this->assertSame([1, 2, 3], $this->idsOf($this->rows('organized')));
        $this->assertSame([4, 5], $this->idsOf($this->rows('organized_manga')));

        // Running allocation again directly is also a no-op.
        $summary = $migrator->allocateHiyoriIds();
        $this->assertSame(0, $summary['anime']['allocated']);
        $this->assertSame(0, $summary['manga']['allocated']);
        $this->assertSame(0, (int) $this->client->selectDatabase(self::DB)->selectCollection('_hiyori_ids')->countDocuments(['hiyori_id' => ['$exists' => false]]));

        // A replay of the whole migration (fresh applied set) must reuse ids.
        $replay = new Migrator($db, self::DB, []);
        $replay->run(3);
        $this->assertSame([1, 2, 3], $this->idsOf($this->rows('organized')));
        $this->assertSame([4, 5], $this->idsOf($this->rows('organized_manga')));

        $verify = $migrator->verifyHiyoriIds();
        $this->assertTrue($verify['ok'], implode("\n", $verify['problems']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $collection): array
    {
        $cursor = $this->client->selectDatabase(self::DB)->selectCollection($collection)
            ->find([], ['sort' => ['hiyori_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);
        $rows = [];
        foreach ($cursor as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn ($r) => (int) $r['hiyori_id'], $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function slugsOf(array $rows): array
    {
        return array_map(static fn ($r) => (string) $r['slug'], $rows);
    }
}
