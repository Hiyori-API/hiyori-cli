<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Helper;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Database;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * The candidate lookup used to filter on `{type, status, year}` and take the
 * first hundred rows in natural order — an arbitrary slice of the pool of
 * same-year, same-type documents. Anime merged 77 % of what was available; manga
 * merged 19 % of the 47.5 % that had an exact-title twin, because the
 * counterpart was almost never in the slice.
 */
final class CandidateRetrievalTest extends TestCase
{
    /** A scratch collection in the live database, dropped afterwards. */
    private const COLLECTION = 'candidates_retrieval_test';

    private Client $client;

    private Database $database;

    protected function setUp(): void
    {
        $_ENV['MONGODB_URL'] ??= $_SERVER['MONGODB_URL'] ?? 'mongodb://localhost:27017';
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->selectDatabase('hiyori')->selectCollection(self::COLLECTION)->drop();
        $this->database = new Database(SerializerBuilder::create()->build());
    }

    protected function tearDown(): void
    {
        $this->client->selectDatabase('hiyori')->selectCollection(self::COLLECTION)->drop();
    }

    private function insert(string $title, int $year, string $ref): void
    {
        $this->client->selectDatabase('hiyori')->selectCollection(self::COLLECTION)->insertOne([
            'title' => $title,
            'title_norm' => Helper::normalizeTitleKey($title),
            'type' => 'manga',
            'status' => 'finished',
            'year' => $year,
            'chapters' => 10,
            'volumes' => 2,
            'synonyms' => [],
            'tags' => [],
            'images' => [],
            'reference_ids' => ['k_manga' => $ref],
            'references' => [],
        ]);
    }

    public function testTheExactTitleMatchIsFoundEvenOutsideTheMetadataWindow(): void
    {
        // 150 documents from the same year and status, none of them the
        // counterpart. The metadata window takes the first hundred, so the
        // counterpart at the end would never be scored.
        for ($i = 0; $i < 150; $i++) {
            $this->insert('Filler ' . $i, 1994, 'filler-' . $i);
        }
        $this->insert('Berserk', 1994, 'the-one');

        $base = [
            'title' => 'Berserk',
            'type' => 'manga',
            'status' => 'finished',
            'year' => 1994,
            'chapters' => 13,
            'volumes' => 27,
        ];

        $candidates = MetadataFuzzyStrategy::candidates($this->database, self::COLLECTION, $base);

        $refs = array_map(static fn (array $c): string => (string) ($c['reference_ids']['k_manga'] ?? ''), $candidates);
        $this->assertContains('the-one', $refs, 'the exact title match must be found');
    }

    public function testRomanisationVariantsShareTheKey(): void
    {
        $this->insert('Matamata O-bo-re-ta-i', 1994, 'variant');

        $base = [
            'title' => 'Mata Mata Oboretai',
            'type' => 'manga',
            'status' => 'finished',
            'year' => 1994,
        ];

        $candidates = MetadataFuzzyStrategy::candidates($this->database, self::COLLECTION, $base);
        $refs = array_map(static fn (array $c): string => (string) ($c['reference_ids']['k_manga'] ?? ''), $candidates);
        $this->assertContains('variant', $refs);
    }

    public function testCandidatesCarryTheFieldsMangaAreJudgedOn(): void
    {
        $this->insert('Berserk', 1994, 'the-one');

        $base = ['title' => 'Berserk', 'type' => 'manga', 'status' => 'finished', 'year' => 1994];
        $candidates = MetadataFuzzyStrategy::candidates($this->database, self::COLLECTION, $base);

        $this->assertNotEmpty($candidates);
        $this->assertArrayHasKey('chapters', $candidates[0]);
        $this->assertArrayHasKey('volumes', $candidates[0]);
    }
}
