<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Models\Anime\Base\AnimeBase;
use Hiyori\Service\Database;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class CombinerTest extends TestCase
{
    private static Database $db;

    private static Client $client;

    private static string $testCollection = 'organized_test';

    public static function setUpBeforeClass(): void
    {
        $dotenv = new \Symfony\Component\Dotenv\Dotenv();
        $dotenv->load(__DIR__ . '/../../.env');

        self::$client = new Client($_ENV['MONGODB_URL']);
        self::$db = new Database(SerializerBuilder::create()->build());
    }

    public static function tearDownAfterClass(): void
    {
        // Cleanup test data
        self::$client->hiyori->selectCollection(self::$testCollection)->drop([]);
    }

    public function testMergeIntoOrganizedUpsertsInsteadOfDuplicating(): void
    {
        $col = self::$client->hiyori->selectCollection(self::$testCollection);
        $col->drop();

        // First merge: should insert
        $entry1 = AnimeBase::create([
            'title' => 'Test Anime',
            'synonyms' => ['Test Anime'],
            'type' => 'tv',
            'episodes' => 12,
            'status' => 'finished',
            'season' => 'spring',
            'year' => 2020,
            'image' => ['https://example.com/a.jpg'],
            'reference_ids' => ['al' => '999001'],
            'references' => ['https://anilist.co/anime/999001', 'https://myanimelist.net/anime/999002'],
            'tags' => ['action'],
        ]);
        self::$db->mergeIntoOrganized($entry1, 'al', '999001', self::$testCollection);

        $countAfterFirst = $col->countDocuments();
        $this->assertSame(1, $countAfterFirst, 'First merge should insert exactly 1 document');

        // Second merge with same key: should REPLACE (upsert), not insert a new doc
        $entry2 = AnimeBase::create([
            'title' => 'Test Anime Updated',
            'synonyms' => ['Test Anime Updated'],
            'type' => 'movie',
            'episodes' => 1,
            'status' => 'finished',
            'season' => 'fall',
            'year' => 2021,
            'image' => ['https://example.com/b.jpg'],
            'reference_ids' => ['al' => '999001'],
            'references' => ['https://anilist.co/anime/999001'],
            'tags' => ['drama'],
        ]);
        self::$db->mergeIntoOrganized($entry2, 'al', '999001', self::$testCollection);

        $countAfterSecond = $col->countDocuments();
        $this->assertSame(
            1,
            $countAfterSecond,
            'Re-merging the same reference_ids key must upsert (replace), not duplicate. Got ' . $countAfterSecond . ' docs.',
        );

        $doc = $col->findOne(['reference_ids.al' => '999001']);
        $this->assertSame('Test Anime Updated', $doc['title'], 'Doc should be replaced with the newer entry.');
    }
}
