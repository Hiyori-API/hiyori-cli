<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Helper;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use PHPUnit\Framework\TestCase;

final class MetadataFuzzyAnchorTest extends TestCase
{
    public function testAnchorTypeReducesVariantSuffixes(): void
    {
        $this->assertSame('tv', MetadataFuzzyStrategy::anchorType('tv_short'));
        $this->assertSame('tv', MetadataFuzzyStrategy::anchorType('TV'));
        $this->assertSame('movie', MetadataFuzzyStrategy::anchorType('Movie'));
        $this->assertSame('', MetadataFuzzyStrategy::anchorType(null));
    }

    public function testTypeFamilyMismatchIsRejected(): void
    {
        $this->assertTrue(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 2020],
            ['type' => 'movie', 'year' => 2020],
        ));
        $this->assertFalse(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 2020],
            ['type' => 'tv_short', 'year' => 2020],
        ));
    }

    public function testYearBeyondToleranceIsRejected(): void
    {
        $this->assertTrue(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 1998],
            ['type' => 'tv', 'year' => 2015],
        ));
        $this->assertFalse(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 1998],
            ['type' => 'tv', 'year' => 1999],
            1,
        ));
    }

    public function testYearAnchorIsSkippedWhenToleranceNegative(): void
    {
        $this->assertFalse(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 1998],
            ['type' => 'tv', 'year' => 2015],
            -1,
        ));
    }

    public function testEpisodesAnchorOnlyWhenEnabled(): void
    {
        $this->assertFalse(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 2020, 'episodes' => 12],
            ['type' => 'tv', 'year' => 2020, 'episodes' => 24],
        ));
        $this->assertTrue(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv', 'year' => 2020, 'episodes' => 12],
            ['type' => 'tv', 'year' => 2020, 'episodes' => 24],
            1,
            true,
        ));
    }

    public function testMissingYearDoesNotReject(): void
    {
        $this->assertFalse(MetadataFuzzyStrategy::isAnchorRejected(
            ['type' => 'tv'],
            ['type' => 'tv', 'year' => 2020],
        ));
    }

    public function testMatchSkipsAnchorRejectedCandidates(): void
    {
        $entry = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26];
        $candidates = [
            ['reference_ids' => ['mal' => '999'], 'title' => 'Cowboy Bebop', 'type' => 'movie', 'year' => 1998, 'episodes' => 26],
            ['reference_ids' => ['mal' => '1'], 'title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26],
        ];

        $result = MetadataFuzzyStrategy::match(
            $entry,
            $candidates,
            MetadataFuzzyStrategy::DEFAULT_WEIGHTS,
            70.0,
        );

        $this->assertSame(2, $result['examined']);
        $this->assertSame(1, $result['rejected_anchor']);
        $this->assertNotNull($result['best']);
        $this->assertSame('1', $result['best']['reference_ids']['mal']);
    }

    public function testUnicodeExactTitleMatches(): void
    {
        // Full-width Latin should fold to the ASCII form.
        $this->assertSame(
            1.0,
            MetadataFuzzyStrategy::titleSimilarity('Ｃｏｗｂｏｙ Ｂｅｂｏｐ', 'Cowboy Bebop', [], []),
        );
        // Half-width katakana should fold to full-width under NFKC.
        $this->assertSame(Helper::normalizeTitleUnicode('テスト'), Helper::normalizeTitleUnicode('ﾃｽﾄ'));
    }
}
