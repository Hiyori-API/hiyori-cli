<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\AnimeBase;
use Hiyori\Models\Manga\Base\MangaBase;
use PHPUnit\Framework\TestCase;

final class HelperTest extends TestCase
{
    public function testCleanseTrimsWhitespace(): void
    {
        $this->assertSame('clean', Helper::cleanse('  clean  '));
        $this->assertSame('clean', Helper::cleanse("\tclean\n"));
        $this->assertSame('', Helper::cleanse('   '));
    }

    public function testPrepareAsIdentifierLowercasesAndTrims(): void
    {
        $this->assertSame('myanimelist', Helper::prepareAsIdentifer('  MyAnimeList  '));
        $this->assertSame('kitsu', Helper::prepareAsIdentifer('KITSU'));
    }

    public function testToArrayValidJson(): void
    {
        $json = '{"key": "value", "num": 123}';
        $result = Helper::toArray($json);
        $this->assertSame(['key' => 'value', 'num' => 123], $result);
    }

    public function testToArrayInvalidJsonThrows(): void
    {
        $this->expectException(\TypeError::class);
        Helper::toArray('not valid json');
    }

    public function testConvertMongoResultToArrayHandlesBSONDocument(): void
    {
        $doc = new \MongoDB\Model\BSONDocument(['key' => 'value', 'num' => 42]);
        $result = Helper::convertMongoResultToArray($doc);
        // Now preserves numeric types
        $this->assertSame(['key' => 'value', 'num' => 42], $result);
    }

    public function testConvertMongoResultToArrayHandlesBSONArray(): void
    {
        $arr = new \MongoDB\Model\BSONArray(['a', 'b', 'c']);
        $result = Helper::convertMongoResultToArray($arr);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testConvertMongoResultToArrayHandlesObjectId(): void
    {
        $oid = new \MongoDB\BSON\ObjectId('507f1f77bcf86cd799439011');
        $result = Helper::convertMongoResultToArray($oid);
        $this->assertSame('507f1f77bcf86cd799439011', $result);
    }

    public function testConvertMongoResultToArrayNested(): void
    {
        $doc = new \MongoDB\Model\BSONDocument([
            'id' => new \MongoDB\BSON\ObjectId('507f1f77bcf86cd799439011'),
            'items' => new \MongoDB\Model\BSONArray([1, 2, 3]),
            'nested' => new \MongoDB\Model\BSONDocument(['foo' => 'bar']),
        ]);
        $result = Helper::convertMongoResultToArray($doc);
        // Now preserves numeric types in arrays
        $this->assertSame([
            'id' => '507f1f77bcf86cd799439011',
            'items' => [1, 2, 3],
            'nested' => ['foo' => 'bar'],
        ], $result);
    }

    public function testConvertMongoResultToArrayPassesThroughPrimitives(): void
    {
        $this->assertSame('string', Helper::convertMongoResultToArray('string'));
        $this->assertSame(123, Helper::convertMongoResultToArray(123));
        $this->assertSame(3.14, Helper::convertMongoResultToArray(3.14));
        $this->assertSame(true, Helper::convertMongoResultToArray(true));
        $this->assertSame(false, Helper::convertMongoResultToArray(false));
    }

    public function testConvertMongoResultToArrayNullThrows(): void
    {
        // Now returns null instead of throwing (wider return type)
        $this->assertNull(Helper::convertMongoResultToArray(null));
    }

    public function testResolveReferenceIdUsesConfiguredShorthandFirst(): void
    {
        $entry = ['reference_ids' => ['al' => '19', 'al_manga' => '9019']];
        $this->assertSame('9019', Helper::resolveReferenceId($entry, 'al_manga'));
        $this->assertSame('19', Helper::resolveReferenceId($entry, 'al'));
    }

    public function testResolveReferenceIdFallsBackFromMangaShorthand(): void
    {
        // Manga sources are ingested with canonical keys ('al', 'k', 'mal')
        // while the combine config declares mangafied shorthands ('al_manga').
        $entry = ['reference_ids' => ['al' => '19']];
        $this->assertSame('19', Helper::resolveReferenceId($entry, 'al_manga'));
    }

    public function testResolveReferenceIdReturnsEmptyWhenMissing(): void
    {
        // Reference-less manga rows (empty array) must resolve to '' so the
        // combiner skips them instead of collapsing onto an empty upsert key.
        $this->assertSame('', Helper::resolveReferenceId(['reference_ids' => []], 'al_manga'));
        $this->assertSame('', Helper::resolveReferenceId(['reference_ids' => ['k' => '8']], 'al_manga'));
        $this->assertSame('', Helper::resolveReferenceId(['title' => 'x'], 'al_manga'));
        $this->assertSame('', Helper::resolveReferenceId(['reference_ids' => 'scalar'], 'al_manga'));
    }

    public function testMergeEntriesUsesMangaModelForMangaTable(): void
    {
        $entry = [
            'title' => 'MONSTER',
            'synonyms' => ['MONSTER'],
            'type' => 'manga',
            'status' => 'finished',
            'year' => 1994,
            'chapters' => 162,
            'volumes' => 18,
            'episodes' => null,
            'season' => null,
            'images' => ['https://example.com/monster.jpg'],
            'reference_ids' => ['al' => '30001', 'al_manga' => '19'],
            'references' => ['https://anilist.co/manga/30001'],
            'tags' => ['drama'],
        ];
        $mergeEntry = [
            'title' => 'Monster',
            'synonyms' => [],
            'type' => 'manga',
            'status' => 'finished',
            'year' => 1994,
            'chapters' => 162,
            'volumes' => 18,
            'images' => [],
            'reference_ids' => [],
            'references' => [],
            'tags' => [],
        ];

        $merged = Helper::mergeEntries('anilist_manga', $entry, $mergeEntry);
        $this->assertInstanceOf(MangaBase::class, $merged);
        $this->assertSame(162, $merged->getChapters());
        $this->assertSame(18, $merged->getVolumes());
        $this->assertSame(['al' => '30001', 'al_manga' => '19'], $merged->getReferenceIds());
    }

    public function testMergeEntriesUsesAnimeModelForAnimeTable(): void
    {
        $entry = [
            'title' => 'Cowboy Bebop',
            'synonyms' => [],
            'type' => 'tv',
            'status' => 'finished',
            'season' => 'spring',
            'year' => 1998,
            'episodes' => 26,
            'images' => [],
            'reference_ids' => ['al' => '1'],
            'references' => [],
            'tags' => [],
        ];

        $merged = Helper::mergeEntries('anilist', $entry, $entry);
        $this->assertInstanceOf(AnimeBase::class, $merged);
        $this->assertSame(26, $merged->getEpisodes());
    }
}
