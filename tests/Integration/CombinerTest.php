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

    private static string $mangaTestCollection = 'organized_manga_test';

    public static function setUpBeforeClass(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            $dotenv = new \Symfony\Component\Dotenv\Dotenv();
            $dotenv->load($envFile);
        }

        self::$client = new Client($_ENV['MONGODB_URL']);
        self::$db = new Database(SerializerBuilder::create()->build());
    }

    public static function tearDownAfterClass(): void
    {
        // Cleanup test data
        self::$client->hiyori->selectCollection(self::$testCollection)->drop([]);
        self::$client->hiyori->selectCollection(self::$mangaTestCollection)->drop([]);
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

    public function testMangaMergeIntoCustomCollectionUsesMangaShorthandAndDedups(): void
    {
        $col = self::$client->hiyori->selectCollection(self::$mangaTestCollection);
        $col->drop();

        $entry1 = \Hiyori\Models\Manga\Base\MangaBase::create([
            'title' => 'MONSTER',
            'synonyms' => ['Monster', 'MONSTER'],
            'type' => 'manga',
            'chapters' => 162,
            'volumes' => 18,
            'status' => 'finished',
            'year' => 1994,
            'images' => ['https://example.com/monster.jpg'],
            'reference_ids' => ['al' => '19', 'al_manga' => '90019'],
            'references' => ['https://anilist.co/manga/30001'],
            'tags' => ['drama'],
        ]);
        // --into organized_manga equivalent: keyed by the mangafied shorthand
        // in a per-call collection, keeping manga fields (chapters/volumes).
        self::$db->mergeIntoOrganized($entry1, 'al_manga', '90019', self::$mangaTestCollection, ['confidence' => 90.0]);

        $this->assertSame(1, $col->countDocuments(), 'First manga merge should insert exactly 1 document');

        $doc = $col->findOne(['reference_ids.al_manga' => '90019']);
        $this->assertNotNull($doc, 'Manga doc must be keyed by reference_ids.al_manga');
        $this->assertSame(162, $doc['chapters'], 'Manga merge must preserve chapters');
        $this->assertSame(18, $doc['volumes'], 'Manga merge must preserve volumes');
        $this->assertEquals(90.0, $doc['confidence']);

        // Second merge with the same al_manga key must upsert, not duplicate.
        $entry2 = \Hiyori\Models\Manga\Base\MangaBase::create([
            'title' => 'MONSTER (Updated)',
            'synonyms' => ['Monster'],
            'type' => 'manga',
            'chapters' => 162,
            'volumes' => 18,
            'status' => 'finished',
            'year' => 1994,
            'images' => [],
            'reference_ids' => ['al' => '19', 'al_manga' => '90019'],
            'references' => ['https://anilist.co/manga/30001'],
            'tags' => ['drama'],
        ]);
        self::$db->mergeIntoOrganized($entry2, 'al_manga', '90019', self::$mangaTestCollection, ['confidence' => 92.0]);

        $this->assertSame(1, $col->countDocuments(), 'Re-merging the same al_manga key must upsert, not duplicate');
        $updated = $col->findOne(['reference_ids.al_manga' => '90019']);
        $this->assertSame('MONSTER (Updated)', $updated['title']);
    }
}
