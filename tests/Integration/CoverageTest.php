<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Coverage;
use Hiyori\Service\Stats;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * The coverage matrix against a real MongoDB.
 *
 * The interesting part is the normalisation of `reference_ids`: a document
 * that carries `reference_ids: []` (some ingesters write an empty array for
 * "no references") must not be counted as carrying every reference, and a
 * document that lists the same reference twice must be counted once.
 *
 * Everything runs against the throwaway `hiyori_test_coverage` database,
 * which is dropped afterwards. The live `hiyori` database is never touched.
 */
final class CoverageTest extends TestCase
{
    private const DB_NAME = 'hiyori_test_coverage';

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
            ['title' => 'both', 'reference_ids' => ['al' => '1', 'mal' => '2']],
            ['title' => 'kitsu only', 'reference_ids' => ['k' => '3']],
            ['title' => 'no references at all', 'reference_ids' => []],
            ['title' => 'no field'],
            ['title' => 'duplicated', 'reference_ids' => [['al' => '9'], ['al' => '9']]],
        ]);
    }

    private function coverage(): Coverage
    {
        return new Coverage(self::$client, self::DB_NAME, new Stats(self::$client, self::DB_NAME));
    }

    public function testMatrixCountsEachReferenceKeyOncePerDocument(): void
    {
        $matrix = $this->coverage()->matrix();

        $this->assertArrayHasKey('organized', $matrix);
        $row = $matrix['organized'];

        $this->assertSame(5, $row['total']);
        $this->assertSame(2, $row['al'], 'the al key is present on two documents (the duplicate is collapsed)');
        $this->assertSame(1, $row['mal']);
        $this->assertSame(1, $row['k']);
    }

    public function testEmptyReferenceIdArrayCountsAsNoReference(): void
    {
        $row = $this->coverage()->matrix()['organized'];

        // `reference_ids: []` and a missing field must not be read as "every
        // reference is present".
        $this->assertSame(0, $row['adb']);
        $this->assertSame(0, $row['ann']);
        $this->assertSame(0, $row['ttv']);
    }

    public function testMissingnessIsDerivedFromTheTotals(): void
    {
        $row = $this->coverage()->matrix()['organized'];

        $this->assertSame(3, $row['missing_al']);
        $this->assertSame(4, $row['missing_mal']);
        $this->assertSame(4, $row['missing_k']);
    }

    public function testMatrixAcceptsPrecomputedTotals(): void
    {
        $row = $this->coverage()->matrix(['organized' => 5])['organized'];

        $this->assertSame(5, $row['total']);
        $this->assertSame(3, $row['missing_al']);
    }

    public function testSourceTitlesResolvesTheTitleTheSourceHolds(): void
    {
        self::$client->selectDatabase(self::DB_NAME)->selectCollection('anilist')->insertMany([
            ['reference_ids' => ['al' => '1'], 'title' => ['romaji' => 'Sousou no Frieren'], 'year' => 2023, 'type' => 'TV'],
        ]);

        $rows = $this->coverage()->sourceTitles(['al' => '1']);

        $this->assertCount(1, $rows);
        $this->assertSame('al', $rows[0]['shorthand']);
        $this->assertSame('1', $rows[0]['id']);
        $this->assertSame('Sousou no Frieren', $rows[0]['title']);
        $this->assertSame(2023, $rows[0]['year']);
        $this->assertSame('TV', $rows[0]['type']);
    }

    public function testSourceTitlesKeepsUnknownShorthandsAsNullTitleRows(): void
    {
        $rows = $this->coverage()->sourceTitles(['zz' => '42']);

        $this->assertCount(1, $rows);
        $this->assertSame('zz', $rows[0]['shorthand']);
        $this->assertSame('42', $rows[0]['id']);
        $this->assertNull($rows[0]['title']);
        $this->assertNull($rows[0]['year']);
        $this->assertNull($rows[0]['type']);
    }
}
