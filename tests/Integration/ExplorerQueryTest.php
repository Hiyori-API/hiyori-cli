<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\ExplorerQuery;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * The database-aware half of the Explorer.
 *
 * The pure rules (filter building, facet pipelines) live in Hiyori\Service\
 * Explorer and are unit-tested there; this covers the part that actually runs
 * the queries.
 *
 * Everything runs against the throwaway `hiyori_test_explorer` database,
 * which is dropped afterwards. The live `hiyori` database is never touched.
 */
final class ExplorerQueryTest extends TestCase
{
    private const DB_NAME = 'hiyori_test_explorer';

    private static Client $client;

    public static function setUpBeforeClass(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }

        self::$client = new Client($_ENV['MONGODB_URL']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$client->selectDatabase(self::DB_NAME)->drop();
    }

    protected function setUp(): void
    {
        $db = self::$client->selectDatabase(self::DB_NAME);
        $db->drop();
        $db->selectCollection('organized')->insertMany([
            ['title' => 'Cowboy Bebop', 'type' => 'TV', 'score' => 85, 'confidence' => 90, 'episodes' => 26],
            ['title' => 'Trigun', 'type' => 'TV', 'score' => 75, 'confidence' => 80, 'episodes' => 26],
            ['title' => 'Nadia', 'type' => 'Movie', 'score' => 45, 'episodes' => 1],
        ]);
    }

    private function explorer(): ExplorerQuery
    {
        return new ExplorerQuery(self::$client, self::DB_NAME);
    }

    public function testExploreReturnsThePageAndItsFields(): void
    {
        $out = $this->explorer()->explore(['collection' => 'organized', 'per' => 2, 'page' => 1]);

        $this->assertSame('organized', $out['collection']);
        $this->assertSame(3, $out['total']);
        $this->assertCount(2, $out['docs']);
        $this->assertSame(1, $out['page']);
        $this->assertSame(2, $out['per']);
        $this->assertContains('title', $out['fields']);
    }

    public function testExploreRejectsAnUnsafeCollectionName(): void
    {
        $out = $this->explorer()->explore(['collection' => 'organ;ized']);

        $this->assertSame(['error' => 'bad collection'], $out);
    }

    public function testExploreFiltersWithThePureRules(): void
    {
        $out = $this->explorer()->explore(['collection' => 'organized', 'q' => 'Trigun', 'per' => 25]);

        $this->assertSame(1, $out['total']);
        $this->assertSame('Trigun', $out['docs'][0]['title']);
    }

    public function testExploreAddsFacetValues(): void
    {
        $out = $this->explorer()->explore(['collection' => 'organized', 'facets' => 'type']);

        $this->assertArrayHasKey('facets', $out);
        $this->assertSame([['v' => 'TV', 'n' => 2], ['v' => 'Movie', 'n' => 1]], $out['facets']['type']);
    }

    public function testHistogramBinsTheScore(): void
    {
        $histogram = $this->explorer()->histogram('organized', 'score');

        $this->assertCount(10, $histogram);
        $this->assertSame(1, $histogram[40]);
        $this->assertSame(1, $histogram[70]);
        $this->assertSame(1, $histogram[80]);
        $this->assertSame(0, $histogram[0]);
    }

    public function testHistogramWithoutAFieldAddsBothColumns(): void
    {
        $histogram = $this->explorer()->histogram('organized');

        // score: 85 + 75 + 45, confidence: 90 + 80
        $this->assertSame(1, $histogram[40]);
        $this->assertSame(1, $histogram[70]);
        $this->assertSame(2, $histogram[80]);
        $this->assertSame(1, $histogram[90]);
    }

    public function testMetaMissingCountsCountsTheDocumentsMissingEachField(): void
    {
        $coll = $this->explorer()->collection('organized');

        $counts = $this->explorer()->metaMissingCounts($coll, ['title', 'score', 'confidence']);

        $this->assertSame(0, $counts['title']);
        $this->assertSame(0, $counts['score']);
        // Only one document has no confidence.
        $this->assertSame(1, $counts['confidence']);
    }

    public function testMetaMissingCountsIgnoresUnsafeFieldNames(): void
    {
        $coll = $this->explorer()->collection('organized');

        $this->assertSame([], $this->explorer()->metaMissingCounts($coll, ['$where']));
    }
}
