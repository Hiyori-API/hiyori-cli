<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit\Models\Manga;

use Hiyori\Models\Manga\Base\AniListBase;
use PHPUnit\Framework\TestCase;

/**
 * Related works used to be fed into the identity map alongside the entry's own
 * URL. Because getModel() is last-wins, a related manga overwrote the real id,
 * and a related anime put an anime id under `al` on a manga document — the
 * collision that made 2,094 organized_manga rows claim another work's id.
 */
final class MangaIdentityTest extends TestCase
{
    private function node(): array
    {
        return [
            'id' => 30002,
            'title' => ['romaji' => 'Berserk'],
            'synonyms' => [],
            'format' => 'MANGA',
            'chapters' => 1,
            'volumes' => 1,
            'status' => 'FINISHED',
            'startDate' => ['year' => 1989],
            'coverImage' => ['large' => ''],
            'externalLinks' => [['url' => 'https://myanimelist.net/manga/2']],
            'relations' => ['edges' => [
                ['node' => ['siteUrl' => 'https://anilist.co/anime/33']],
                ['node' => ['siteUrl' => 'https://anilist.co/manga/99999']],
            ]],
            'genres' => [],
            'tags' => [],
        ];
    }

    public function testOwnMangaIdIsTheIdentity(): void
    {
        $ids = AniListBase::create($this->node())->getReferenceIds();

        $this->assertSame('30002', $ids['al_manga']);
        $this->assertSame('2', $ids['mal_manga']);
    }

    public function testRelatedWorksDoNotBecomeAliases(): void
    {
        $ids = AniListBase::create($this->node())->getReferenceIds();

        // A related anime must not claim `al` on a manga document.
        $this->assertArrayNotHasKey('al', $ids);
        // A related manga must not overwrite this entry's own id.
        $this->assertNotSame('99999', $ids['al_manga']);
    }

    public function testRelatedWorksAreStillExportedAsLinks(): void
    {
        $refs = AniListBase::create($this->node())->getReferences();

        $this->assertContains('https://anilist.co/manga/30002', $refs);
        $this->assertContains('https://anilist.co/anime/33', $refs);
        $this->assertContains('https://anilist.co/manga/99999', $refs);
    }
}
