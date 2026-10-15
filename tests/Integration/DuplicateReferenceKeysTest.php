<?php

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class DuplicateReferenceKeysTest extends TestCase
{
    private const DB = 'hiyori_test_dupes';

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

    public function testFlagsPrimaryDuplicatesAndIgnoresFranchiseKeys(): void
    {
        $this->client->selectDatabase(self::DB)->selectCollection('organized')->insertMany([
            // Two entries share one Kitsu franchise id and one Crunchyroll slug
            // — those are reported but are not primary.
            ['title' => 'A', 'reference_ids' => ['mal' => '1', 'al' => '10', 'k' => '99', 'cr' => 'show'], 'hiyori_id' => 1, 'references' => (object) ['0' => 'https://example.com/a']],
            ['title' => 'B', 'reference_ids' => ['mal' => '2', 'al' => '20', 'k' => '99', 'cr' => 'show'], 'hiyori_id' => 2, 'references' => (object) ['0' => 'https://example.com/b']],
            // Genuine duplicate mal across two entries.
            ['title' => 'C', 'reference_ids' => ['mal' => '2', 'al' => '30'], 'hiyori_id' => 3, 'references' => (object) ['0' => 'https://example.com/c']],
        ]);

        $report = $this->migrator()->duplicateReferenceKeys('organized');

        $this->assertSame('organized', $report['collection']);
        $this->assertSame(3, $report['docs']);
        $this->assertSame(['mal', 'al'], $report['primary_keys']);

        // Only the mal collision counts against the gate.
        $this->assertSame(1, $report['primary_duplicates']);
        $this->assertTrue($report['sources']['mal']['primary']);
        $this->assertSame(1, $report['sources']['mal']['duplicates']);
        $this->assertSame(2, $report['sources']['mal']['max']);

        // Franchise keys are reported but not primary.
        $this->assertFalse($report['sources']['k']['primary']);
        $this->assertSame(1, $report['sources']['k']['duplicates']);
        $this->assertSame(1, $report['sources']['cr']['duplicates']);
        $this->assertSame(0, $report['sources']['al']['duplicates']);
    }

    public function testReferencesShapeIsInformationalOnly(): void
    {
        $this->client->selectDatabase(self::DB)->selectCollection('organized')->insertMany([
            // `references` array and document are both current combiner output
            // (array_unique gaps decide the shape). Duplicates are counted the
            // same either way.
            ['title' => 'ArrayShaped', 'reference_ids' => ['mal' => '5'], 'hiyori_id' => 1, 'references' => ['https://example.com/1']],
            ['title' => 'DocShaped', 'reference_ids' => ['mal' => '5'], 'hiyori_id' => 2, 'references' => (object) ['0' => 'https://example.com/2']],
        ]);

        $report = $this->migrator()->duplicateReferenceKeys('organized');

        $this->assertSame(2, $report['docs']);
        $this->assertSame(1, $report['references_array_docs']);
        $this->assertSame(1, $report['primary_duplicates']);
        $this->assertSame(1, $report['sources']['mal']['duplicates']);
    }

    public function testExamplesCarryHiyoriIds(): void
    {
        $this->client->selectDatabase(self::DB)->selectCollection('organized')->insertMany([
            ['title' => 'A', 'reference_ids' => ['al' => '7'], 'hiyori_id' => 41, 'references' => (object) ['0' => 'x']],
            ['title' => 'B', 'reference_ids' => ['al' => '7'], 'hiyori_id' => 42, 'references' => (object) ['0' => 'y']],
        ]);

        $report = $this->migrator()->duplicateReferenceKeys('organized');

        $this->assertSame(1, $report['sources']['al']['duplicates']);
        $this->assertSame('7', $report['sources']['al']['examples'][0]['key']);
        $this->assertEqualsCanonicalizing([41, 42], $report['sources']['al']['examples'][0]['hiyori_ids']);
    }

    private function migrator(): Migrator
    {
        return new Migrator(new Database(SerializerBuilder::create()->build()), self::DB);
    }
}
