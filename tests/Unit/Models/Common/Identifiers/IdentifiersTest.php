<?php

namespace Hiyori\Tests\Unit\Models\Common\Identifiers;

use Hiyori\Models\Common\Identifiers\Identifiers;
use PHPUnit\Framework\TestCase;

final class IdentifiersTest extends TestCase
{
    public function testParseIdMyAnimeList(): void
    {
        [$identifier, $id] = Identifiers::parseId('https://myanimelist.net/anime/12345/Some_Anime');
        $this->assertNotNull($identifier);
        $this->assertSame('12345', $id);
        $this->assertSame('mal', $identifier::SHORTHAND);
    }

    public function testParseIdAniList(): void
    {
        [$identifier, $id] = Identifiers::parseId('https://anilist.co/anime/98765');
        $this->assertNotNull($identifier);
        $this->assertSame('98765', $id);
        $this->assertSame('al', $identifier::SHORTHAND);
    }

    public function testParseIdKitsu(): void
    {
        [$identifier, $id] = Identifiers::parseId('https://kitsu.io/anime/12345');
        $this->assertNotNull($identifier);
        $this->assertSame('12345', $id);
        $this->assertSame('k', $identifier::SHORTHAND);
    }

    public function testParseIdUnknownUrlReturnsNull(): void
    {
        [$identifier, $id] = Identifiers::parseId('https://unknown-site.com/anime/123');
        $this->assertNull($identifier);
        $this->assertNull($id);
    }

    public function testGetModelMultipleSources(): void
    {
        $links = [
            'https://myanimelist.net/anime/12345/Title',
            'https://anilist.co/anime/67890',
            'https://kitsu.io/anime/12345',
        ];
        $identifiers = new Identifiers($links);
        $model = $identifiers->getModel();

        $this->assertArrayHasKey('mal', $model);
        $this->assertArrayHasKey('al', $model);
        $this->assertArrayHasKey('k', $model);
        $this->assertSame('12345', $model['mal']);
        $this->assertSame('67890', $model['al']);
        $this->assertSame('12345', $model['k']);
    }

    public function testGetModelIgnoresUnknownLinks(): void
    {
        $links = [
            'https://myanimelist.net/anime/12345',
            'https://unknown-site.com/anime/999',
        ];
        $identifiers = new Identifiers($links);
        $model = $identifiers->getModel();

        $this->assertArrayHasKey('mal', $model);
        $this->assertCount(1, $model);
        $this->assertSame('12345', $model['mal']);
    }

    public function testPairsToLinks(): void
    {
        $pairs = [
            ['myanimelist.net', '12345'],
            ['anilist.co', '67890'],
        ];
        $links = Identifiers::pairsToLinks($pairs);

        $this->assertCount(2, $links);
        $this->assertContains('https://myanimelist.net/anime/12345', $links);
        $this->assertContains('https://anilist.co/anime/67890', $links);
    }

    public function testPairsToLinksIgnoresUnknownSources(): void
    {
        $pairs = [
            ['myanimelist.net', '12345'],
            ['unknown-source.com', '999'],
        ];
        $links = Identifiers::pairsToLinks($pairs);

        $this->assertCount(1, $links);
        $this->assertContains('https://myanimelist.net/anime/12345', $links);
    }
}
