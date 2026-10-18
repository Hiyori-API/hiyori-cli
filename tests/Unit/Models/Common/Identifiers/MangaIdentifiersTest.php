<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit\Models\Common\Identifiers;

use Hiyori\Models\Common\Identifiers\Identifiers;
use PHPUnit\Framework\TestCase;

/**
 * Manga sources expose `/manga/<id>` URLs and `*_manga` shorthands. The
 * identifier table only knew `/anime/` shapes, so every manga entry ended up
 * with an empty `reference_ids` and the ingest resume check never matched.
 */
final class MangaIdentifiersTest extends TestCase
{
    public function testMangaUrlsParseToMangaShorthands(): void
    {
        $ids = (new Identifiers([
            'https://anilist.co/manga/30002',
            'https://kitsu.io/manga/42/some-slug',
            'https://myanimelist.net/manga/170',
        ]))->getModel();

        $this->assertSame('30002', $ids['al_manga']);
        $this->assertSame('42', $ids['k_manga']);
        $this->assertSame('170', $ids['mal_manga']);
    }

    public function testMangaUrlsDoNotPopulateAnimeShorthands(): void
    {
        $ids = (new Identifiers(['https://anilist.co/manga/30002']))->getModel();

        $this->assertArrayNotHasKey('al', $ids);
        $this->assertArrayNotHasKey('k', $ids);
        $this->assertArrayNotHasKey('mal', $ids);
    }

    public function testAnimeUrlsDoNotPopulateMangaShorthands(): void
    {
        $ids = (new Identifiers(['https://anilist.co/anime/19']))->getModel();

        $this->assertSame('19', $ids['al']);
        $this->assertArrayNotHasKey('al_manga', $ids);
    }

    public function testPairsToLinksIsKindAware(): void
    {
        $pairs = [['myanimelist.net', '12345']];

        $this->assertSame(
            ['https://myanimelist.net/anime/12345'],
            Identifiers::pairsToLinks($pairs),
        );
        $this->assertSame(
            ['https://myanimelist.net/manga/12345'],
            Identifiers::pairsToLinks($pairs, 'manga'),
        );
    }
}
