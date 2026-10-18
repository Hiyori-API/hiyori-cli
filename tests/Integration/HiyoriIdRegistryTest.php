<?php

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use MongoDB\Driver\Exception\BulkWriteException;
use PHPUnit\Framework\TestCase;

/**
 * Mongo-backed coverage for the registry hardening: idempotent allocation
 * after a partial failure, safe partial unique indexes, duplicate-ref
 * detection and which ids never move.
 */
final class HiyoriIdRegistryTest extends TestCase
{
    private const DB = 'hiyori_test_hiyori_id_registry';

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

    public function testBackfillReusesRegistryRowAfterCrashBeforeDocumentWrite(): void
    {
        $this->collection('organized')->insertOne([
            'title' => 'Cowboy Bebop',
            'reference_ids' => ['al' => '1'],
        ]);

        $migrator = $this->migrator();
        $first = $migrator->allocateHiyoriIds();
        $this->assertSame(1, $first['anime']['allocated']);
        $this->assertSame(0, $first['anime']['reused']);

        $doc = $this->collection('organized')->findOne(['reference_ids.al' => '1'], $this->arrayOptions());
        $this->assertSame(1, (int) $doc['hiyori_id']);

        // Simulate a crash between the registry insert and the document
        // update: the registry row survives, the document lost its id.
        $this->collection('organized')->updateOne(['reference_ids.al' => '1'], ['$unset' => ['hiyori_id' => '', 'slug' => '']]);

        $second = $migrator->allocateHiyoriIds();

        $doc = $this->collection('organized')->findOne(['reference_ids.al' => '1'], $this->arrayOptions());
        $this->assertSame(1, (int) $doc['hiyori_id'], 'the existing registry row must be reused, not a second id');
        $this->assertSame('cowboy-bebop', (string) $doc['slug']);
        $this->assertSame(1, $second['anime']['allocated']);
        $this->assertSame(1, $second['anime']['reused']);
        $this->assertSame(1, $this->collection('_hiyori_ids')->countDocuments());
        $this->assertSame(1, $this->collection('_hiyori_ids')->countDocuments(['refs.al' => '1', 'hiyori_id' => 1]));

        // Only the first allocation reserved an id, so the counter did not move.
        $counter = $this->collection('_counters')->findOne(['_id' => 'hiyori_id'], $this->arrayOptions());
        $this->assertSame(2, (int) $counter['next']);
    }

    public function testOrphanRegistryRowIsReusedInsteadOfSecondId(): void
    {
        $this->collection('_hiyori_ids')->insertOne([
            'hiyori_id' => 7,
            'kind' => 'anime',
            'refs' => (object) ['al' => '42'],
            'slug' => 'orphan-entry',
            'legacy_id' => 'orphan',
        ]);
        $this->collection('organized')->insertOne([
            'title' => 'Orphan Entry',
            'reference_ids' => ['al' => '42'],
        ]);

        $migrator = $this->migrator();

        $orphans = $migrator->reconcileRegistryOrphans();
        $this->assertSame(1, $orphans['kinds']['anime']['orphans']);
        $this->assertSame([7], $orphans['kinds']['anime']['examples']);

        $summary = $migrator->allocateHiyoriIds();

        $doc = $this->collection('organized')->findOne(['reference_ids.al' => '42'], $this->arrayOptions());
        $this->assertSame(7, (int) $doc['hiyori_id']);
        $this->assertSame('orphan-entry', (string) $doc['slug']);
        $this->assertSame(1, $summary['anime']['reused']);
        $this->assertSame(1, $this->collection('_hiyori_ids')->countDocuments());
        // Reconciliation is read-only; the orphan row is still there and still
        // owns its id.
        $this->assertSame(1, $this->collection('_hiyori_ids')->countDocuments(['hiyori_id' => 7]));
    }

    public function testIdentityIndexIsBlockedUntilDuplicatesAreResolved(): void
    {
        $this->collection('_hiyori_ids')->insertMany([
            ['hiyori_id' => 1, 'kind' => 'anime', 'refs' => (object) ['al' => '1', 'mal' => '5'], 'slug' => 'first'],
            ['hiyori_id' => 2, 'kind' => 'anime', 'refs' => (object) ['al' => '2', 'mal' => '5'], 'slug' => 'second'],
        ]);

        $migrator = $this->migrator();

        $report = $migrator->ensureRegistryIndexes();
        $this->assertArrayHasKey('mal', $report['blocked']);
        $this->assertSame('duplicate_references', $report['blocked']['mal']['reason']);
        $this->assertSame(1, $report['blocked']['mal']['duplicates']);
        $this->assertFalse($this->hasIndex('kind_refs_mal_1'), 'unique mal index must not exist while duplicates do');
        $this->assertTrue($this->hasIndex('kind_refs_al_1', unique: true), 'conflict-free al can be made unique');
        $this->assertTrue($this->hasIndex('refs_mal_1'), 'lookups stay indexed while uniqueness is blocked');

        // Dry run reports but writes nothing.
        $dry = $migrator->stripDuplicateRegistryIdentityKeys(dryRun: true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(1, $dry['resolved']);
        $this->assertFalse($this->hasIndex('kind_refs_mal_1'));
        $this->assertArrayHasKey('mal', $migrator->ensureRegistryIndexes()['blocked']);

        // Real resolution: smallest id keeps the ref, no id changes.
        $resolved = $migrator->stripDuplicateRegistryIdentityKeys();
        $this->assertSame(1, $resolved['resolved']);
        $this->assertSame(1, $resolved['keys'][0]['keep']);
        $this->assertSame([2], $resolved['keys'][0]['dropped']);
        $this->assertSame(2, $this->collection('_hiyori_ids')->countDocuments(['hiyori_id' => ['$in' => [1, 2]]]));
        $loser = $this->collection('_hiyori_ids')->findOne(['hiyori_id' => 2], $this->arrayOptions());
        $this->assertArrayNotHasKey('mal', $loser['refs']);

        $after = $migrator->ensureRegistryIndexes();
        $this->assertArrayNotHasKey('mal', $after['blocked']);
        $this->assertContains('_hiyori_ids.kind_refs_mal_1', $after['created']);
        $this->assertTrue($this->hasIndex('kind_refs_mal_1', unique: true));

        // The partial index leaves rows without the key alone…
        $this->collection('_hiyori_ids')->insertMany([
            ['hiyori_id' => 3, 'kind' => 'anime', 'refs' => (object) ['al' => '3'], 'slug' => 'third'],
            ['hiyori_id' => 4, 'kind' => 'anime', 'refs' => (object) ['al' => '4'], 'slug' => 'fourth'],
        ]);

        // …and now rejects a re-introduced duplicate reference.
        $this->expectException(BulkWriteException::class);
        $this->collection('_hiyori_ids')->insertOne([
            'hiyori_id' => 5,
            'kind' => 'anime',
            'refs' => (object) ['al' => '5', 'mal' => '5'],
            'slug' => 'fifth',
        ]);
    }

    public function testVerifyDetectsDuplicateRegistryReferences(): void
    {
        $this->collection('organized')->insertMany([
            ['title' => 'A', 'reference_ids' => ['al' => '1', 'mal' => '5'], 'hiyori_id' => 1, 'slug' => 'a'],
            ['title' => 'B', 'reference_ids' => ['al' => '2'], 'hiyori_id' => 2, 'slug' => 'b'],
        ]);
        $this->collection('_hiyori_ids')->insertMany([
            ['hiyori_id' => 1, 'kind' => 'anime', 'refs' => (object) ['al' => '1', 'mal' => '5'], 'slug' => 'a'],
            ['hiyori_id' => 2, 'kind' => 'anime', 'refs' => (object) ['al' => '2', 'mal' => '5'], 'slug' => 'b'],
        ]);

        $verify = $this->migrator()->verifyHiyoriIds();

        $this->assertFalse($verify['ok']);
        $this->assertSame(1, $verify['checked']['registry_duplicate_refs']);
        $this->assertStringContainsString('duplicate refs.mal "5"', implode("\n", $verify['problems']));
    }

    public function testVerifyDetectsDuplicateDocumentReferences(): void
    {
        $this->collection('organized')->insertMany([
            ['title' => 'A', 'reference_ids' => ['al' => '1', 'mal' => '7'], 'hiyori_id' => 1, 'slug' => 'a'],
            ['title' => 'B', 'reference_ids' => ['al' => '2', 'mal' => '7'], 'hiyori_id' => 2, 'slug' => 'b'],
        ]);
        $this->collection('_hiyori_ids')->insertMany([
            ['hiyori_id' => 1, 'kind' => 'anime', 'refs' => (object) ['al' => '1', 'mal' => '7'], 'slug' => 'a'],
            ['hiyori_id' => 2, 'kind' => 'anime', 'refs' => (object) ['al' => '2'], 'slug' => 'b'],
        ]);

        $verify = $this->migrator()->verifyHiyoriIds();

        $this->assertFalse($verify['ok']);
        $this->assertSame(1, $verify['checked']['document_duplicate_refs']);
        $this->assertStringContainsString('duplicate reference_ids.mal "7"', implode("\n", $verify['problems']));
    }

    public function testReconcileOrphansIsReadOnlyAndReportsAllKinds(): void
    {
        $this->collection('_hiyori_ids')->insertMany([
            ['hiyori_id' => 99, 'kind' => 'anime', 'refs' => (object) ['al' => '99'], 'slug' => 'orphan'],
            ['hiyori_id' => 100, 'kind' => 'manga', 'refs' => (object) ['al_manga' => '100'], 'slug' => 'manga-orphan'],
        ]);

        $orphans = $this->migrator()->reconcileRegistryOrphans();

        $this->assertSame(1, $orphans['kinds']['anime']['orphans']);
        $this->assertSame(1, $orphans['kinds']['manga']['orphans']);
        $this->assertSame(2, $orphans['total']);
        $this->assertSame(2, $this->collection('_hiyori_ids')->countDocuments(), 'reconciliation must not delete rows');
    }

    private function migrator(): Migrator
    {
        return new Migrator(new Database(SerializerBuilder::create()->build()), self::DB);
    }

    private function collection(string $name): \MongoDB\Collection
    {
        return $this->client->selectDatabase(self::DB)->selectCollection($name);
    }

    /** @return array<string, mixed> */
    private function arrayOptions(): array
    {
        return ['typeMap' => ['root' => 'array', 'document' => 'array']];
    }

    private function hasIndex(string $name, bool $unique = false): bool
    {
        foreach ($this->collection('_hiyori_ids')->listIndexes() as $index) {
            if ($index->getName() === $name) {
                return !$unique || $index->isUnique();
            }
        }

        return false;
    }
}
