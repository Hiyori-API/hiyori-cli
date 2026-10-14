<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Pure (no-DB) coverage for the hiyori_id helpers: slug normalization,
 * per-kind collision suffixing and the "reuse a known id" lookup.
 */
final class HiyoriIdTest extends TestCase
{
    public function testNormalizeSlugLowercasesAndCollapses(): void
    {
        $this->assertSame('cowboy-bebop', Migrator::normalizeSlug('Cowboy Bebop'));
        $this->assertSame('fullmetal-alchemist-brotherhood', Migrator::normalizeSlug('Fullmetal Alchemist: Brotherhood!'));
        $this->assertSame('multiple-spaces', Migrator::normalizeSlug('  Multiple   Spaces  '));
        $this->assertSame('re-zero-kara-hajimeru', Migrator::normalizeSlug('Re:Zero - Kara Hajimeru!!'));
    }

    public function testNormalizeSlugFallsBackToUntitled(): void
    {
        $this->assertSame('untitled', Migrator::normalizeSlug('###'));
        $this->assertSame('untitled', Migrator::normalizeSlug(''));
        $this->assertSame('untitled', Migrator::normalizeSlug('  ---  '));
    }

    public function testNormalizeSlugTrimsTo120Chars(): void
    {
        $slug = Migrator::normalizeSlug(str_repeat('ab ', 100));
        $this->assertSame(120, strlen($slug));
        $this->assertSame(substr(str_repeat('ab-', 100), 0, 120), $slug);
    }

    public function testUniqueSlugSuffixesOnCollision(): void
    {
        $this->assertSame('naruto', Migrator::uniqueSlug('naruto', []));
        $this->assertSame('naruto-2', Migrator::uniqueSlug('naruto', ['naruto' => true]));
        $this->assertSame('naruto-3', Migrator::uniqueSlug('naruto', ['naruto' => true, 'naruto-2' => true]));
        $this->assertSame('naruto-4', Migrator::uniqueSlug('naruto', ['naruto' => true, 'naruto-2' => true, 'naruto-3' => true]));
        // an unrelated slug must not influence the suffix
        $this->assertSame('naruto-2', Migrator::uniqueSlug('naruto', ['bleach' => true, 'naruto' => true]));
    }

    public function testUniqueSlugDoesNotReuseACollidingSuffix(): void
    {
        // "naruto-2" already exists as its own base slug; "naruto" must skip it.
        $used = ['naruto' => true, 'naruto-2' => true, 'naruto-3' => true];
        $this->assertSame('naruto-4', Migrator::uniqueSlug('naruto', $used));
    }

    public function testMatchKnownIdReusesIdForSharedReference(): void
    {
        $rows = [
            ['hiyori_id' => 7, 'kind' => 'anime', 'legacy_id' => 'abc', 'refs' => ['al' => '1', 'mal' => '1']],
        ];

        $this->assertSame(7, Migrator::matchKnownId($rows, 'anime', ['al' => '1'], ''));
        $this->assertSame(7, Migrator::matchKnownId($rows, 'anime', [], 'abc'));
        $this->assertSame(7, Migrator::matchKnownId($rows, 'anime', ['mal' => '1', 'k' => '9'], ''));
    }

    public function testMatchKnownIdIgnoresOtherKindsAndUnknownRefs(): void
    {
        $rows = [
            ['hiyori_id' => 7, 'kind' => 'manga', 'legacy_id' => 'abc', 'refs' => ['al_manga' => '1']],
        ];

        $this->assertNull(Migrator::matchKnownId($rows, 'anime', ['al' => '1'], ''));
        $this->assertNull(Migrator::matchKnownId($rows, 'anime', [], ''));
        $this->assertNull(Migrator::matchKnownId([], 'anime', ['al' => '1'], 'abc'));
    }

    public function testMatchKnownIdReturnsLowestOnAmbiguity(): void
    {
        $rows = [
            ['hiyori_id' => 42, 'kind' => 'anime', 'legacy_id' => '', 'refs' => ['al' => '1']],
            ['hiyori_id' => 9, 'kind' => 'anime', 'legacy_id' => '', 'refs' => ['al' => '1']],
        ];

        $this->assertSame(9, Migrator::matchKnownId($rows, 'anime', ['al' => '1'], ''));
    }

    public function testNormalizeRefsStringifiesSortsAndDropsNoise(): void
    {
        $this->assertSame(
            ['al' => '1', 'k' => '2', 'mal' => '3'],
            Migrator::normalizeRefs(['mal' => 3, 'al' => '1', 'k' => 2, 'empty' => '', 'nested' => ['x']]),
        );
        $this->assertSame([], Migrator::normalizeRefs([]));
    }

    public function testTitleOfHandlesScalarsListsAndMaps(): void
    {
        $this->assertSame('Naruto', Migrator::titleOf('Naruto'));
        $this->assertSame('ローマ字', Migrator::titleOf(['romaji' => 'ローマ字', 'native' => 'ナルト']));
        $this->assertSame('첫번째', Migrator::titleOf(['첫번째', '두번째']));
        $this->assertSame('42', Migrator::titleOf(42));
        $this->assertSame('', Migrator::titleOf([]));
    }
}
