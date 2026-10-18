<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\ReferenceRepair;
use PHPUnit\Framework\TestCase;

/**
 * The repair recomputes a manga document's identity from the URLs it already
 * stores. It has to recover the entry's own id while refusing the related works
 * that were mixed into the same list — otherwise it re-imports the exact
 * contamination it exists to remove.
 */
final class ReferenceRepairTest extends TestCase
{
    public function testOwnUrlBecomesTheIdentity(): void
    {
        $this->assertSame(
            ['al_manga' => '30001'],
            ReferenceRepair::identityFromReferences(['https://anilist.co/manga/30001']),
        );
    }

    public function testEachSourceMapsToItsOwnMangaShorthand(): void
    {
        $this->assertSame(
            ['k_manga' => '42'],
            ReferenceRepair::identityFromReferences(['https://kitsu.io/manga/42/some-slug']),
        );
        $this->assertSame(
            ['mal_manga' => '1706'],
            ReferenceRepair::identityFromReferences(['https://myanimelist.net/manga/1706']),
        );
    }

    public function testRelatedAnimeDoesNotClaimAnAnimeKey(): void
    {
        // The live MONSTER row: al=19 is the anime adaptation's id.
        $ids = ReferenceRepair::identityFromReferences([
            'https://anilist.co/manga/30001',
            'https://anilist.co/anime/19',
        ]);

        $this->assertSame(['al_manga' => '30001'], $ids);
        $this->assertArrayNotHasKey('al', $ids);
    }

    public function testRelatedMangaDoesNotOverwriteTheOwnId(): void
    {
        $ids = ReferenceRepair::identityFromReferences([
            'https://anilist.co/manga/30001',
            'https://anilist.co/manga/40968',
        ]);

        $this->assertSame(['al_manga' => '30001'], $ids);
    }

    public function testGenuineCrossSourceLinkIsAdopted(): void
    {
        $ids = ReferenceRepair::identityFromReferences([
            'https://anilist.co/manga/30001',
            'https://anilist.co/anime/19',
            'https://myanimelist.net/manga/2',
        ]);

        $this->assertSame(['al_manga' => '30001', 'mal_manga' => '2'], $ids);
    }

    public function testAnimeOwnUrlIsRefused(): void
    {
        // Guard against pointing the repair at an anime collection.
        $this->assertSame([], ReferenceRepair::identityFromReferences(['https://anilist.co/anime/19']));
    }

    public function testEmptyAndUnrecognisedReferencesYieldNothing(): void
    {
        $this->assertSame([], ReferenceRepair::identityFromReferences([]));
        $this->assertSame([], ReferenceRepair::identityFromReferences(['https://example.com/whatever']));
    }

    public function testAcceptsTraversableBecauseMongoReturnsBsonArray(): void
    {
        $ids = ReferenceRepair::identityFromReferences(
            new \ArrayIterator(['https://anilist.co/manga/30001']),
        );

        $this->assertSame(['al_manga' => '30001'], $ids);
    }

    public function testAnimeKeyDetection(): void
    {
        $this->assertTrue(ReferenceRepair::hasAnimeKey(['al' => '19']));
        $this->assertTrue(ReferenceRepair::hasAnimeKey(['al_manga' => '30001', 'mal' => '2']));
        $this->assertFalse(ReferenceRepair::hasAnimeKey(['al_manga' => '30001', 'mal_manga' => '2']));
        $this->assertFalse(ReferenceRepair::hasAnimeKey([]));
    }
}
