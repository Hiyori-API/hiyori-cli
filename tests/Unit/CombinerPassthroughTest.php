<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use MongoDB\UpdateResult;
use PHPUnit\Framework\TestCase;

/**
 * `persistPassthrough` is what keeps one document per base entry. Without it a
 * combine drops every entry that matched nothing, which is how a rebuild loses
 * coverage and leaves registry rows with no document (`hiyori-id verify`).
 */
final class CombinerPassthroughTest extends TestCase
{
    /** @param array<string, mixed> $entry */
    private function persist(array $entry, Database $db, string $sourceName = 'anilist', string $collection = 'organized_test'): bool
    {
        $double = new class () {
            use MergesEntries;

            /** @param array<string, mixed> $entry */
            public function call(Database $db, SourceConfigurationFactory $source, array $entry, string $collection): bool
            {
                return self::persistPassthrough($db, $source, $entry, $collection);
            }
        };

        return $double->call($db, SourceConfigurationFactory::create()->setCurrent($sourceName), $entry, $collection);
    }

    /** @return array<string, mixed> */
    private function animeEntry(): array
    {
        return [
            'title' => 'Lonely Test Anime',
            'synonyms' => [],
            'type' => 'tv',
            'episodes' => 12,
            'status' => 'finished',
            'season' => 'spring',
            'year' => 2020,
            'images' => ['https://example.com/a.jpg'],
            'references' => ['https://anilist.co/anime/777001'],
            'reference_ids' => ['al' => '777001'],
            'tags' => ['action'],
        ];
    }

    public function testWritesOneRecordKeyedByItsOwnReferenceAndWithoutAConfidence(): void
    {
        $entry = $this->animeEntry();

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('allocateHiyoriId')
            ->with('anime', ['al' => '777001'], 'lonely-test-anime')
            ->willReturn(4242);

        $captured = null;
        $db->expects($this->once())
            ->method('mergeIntoOrganized')
            ->willReturnCallback(function ($merged, $identifier, $value, $collection, $extra) use (&$captured): UpdateResult {
                $captured = compact('merged', 'identifier', 'value', 'collection', 'extra');

                return $this->createStub(UpdateResult::class);
            });

        $this->assertTrue($this->persist($entry, $db));

        $this->assertSame('al', $captured['identifier'], 'upsert key is the base entry own source');
        $this->assertSame('777001', $captured['value']);
        $this->assertSame('organized_test', $captured['collection']);
        $this->assertSame(
            ['hiyori_id' => 4242],
            $captured['extra'],
            'a single-source record is not a merge and must not carry a confidence',
        );
        $this->assertSame('Lonely Test Anime', $captured['merged']->getTitle());
        $this->assertSame(['al' => '777001'], $captured['merged']->getReferenceIds());
    }

    public function testMangaCollectionsAllocateMangaIds(): void
    {
        $entry = $this->animeEntry();
        $entry['reference_ids'] = ['al_manga' => '888001'];

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('allocateHiyoriId')
            ->with('manga', ['al_manga' => '888001'], 'lonely-test-anime')
            ->willReturn(5150);
        $db->expects($this->once())
            ->method('mergeIntoOrganized')
            ->willReturn($this->createStub(UpdateResult::class));

        $this->assertTrue($this->persist($entry, $db, 'anilist_manga', 'organized_manga_test'));
    }

    public function testEntryWithoutItsOwnReferenceIsNeverWritten(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('allocateHiyoriId');
        $db->expects($this->never())->method('mergeIntoOrganized');

        $this->assertFalse($this->persist(['title' => 'Reference-less row'], $db), 'nothing to key the document on');
    }
}
