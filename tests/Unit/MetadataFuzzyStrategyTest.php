<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use PHPUnit\Framework\TestCase;

final class MetadataFuzzyStrategyTest extends TestCase
{
    public function testExactTitleScoresOne(): void
    {
        $this->assertSame(1.0, MetadataFuzzyStrategy::titleSimilarity('Cowboy Bebop', 'Cowboy Bebop', [], []));
    }

    public function testTitleIgnoresCaseAndPunctuation(): void
    {
        $this->assertSame(1.0, MetadataFuzzyStrategy::titleSimilarity('Cowboy Bebop!', 'cowboy bebop', [], []));
    }

    public function testSynonymOverlapBoostsTitle(): void
    {
        // Main titles differ but share a synonym
        $sim = MetadataFuzzyStrategy::titleSimilarity(
            'Bebop',
            'Cowboy Bebop: The Movie',
            ['Cowboy Bebop'],
            ['Cowboy Bebop'],
        );
        $this->assertGreaterThan(0.8, $sim);
    }

    public function testContainmentScoresHigh(): void
    {
        $sim = MetadataFuzzyStrategy::titleSimilarity(
            'Cowboy Bebop: Tengoku no Tobira',
            'Cowboy Bebop',
            [],
            [],
        );
        $this->assertGreaterThan(0.75, $sim);
    }

    public function testUnrelatedTitlesScoreLow(): void
    {
        $sim = MetadataFuzzyStrategy::titleSimilarity('Naruto', 'One Piece', [], []);
        $this->assertLessThan(0.5, $sim);
    }

    public function testTokenJaccardPartialMatch(): void
    {
        $same = MetadataFuzzyStrategy::titleSimilarity('Trigun Stampede', 'Trigun', [], []);
        $this->assertGreaterThan(0.0, $same);
    }

    public function testTagsSimilarityJaccard(): void
    {
        $sim = MetadataFuzzyStrategy::tagsSimilarity(['Action', 'Sci-Fi'], ['action', 'space']);
        // 1 intersection / 3 union
        $this->assertEqualsWithDelta(1 / 3, $sim, 0.0001);
        $this->assertSame(1.0, MetadataFuzzyStrategy::tagsSimilarity(['a'], ['a']));
        $this->assertSame(0.0, MetadataFuzzyStrategy::tagsSimilarity(['a'], ['b']));
        $this->assertSame(1.0, MetadataFuzzyStrategy::tagsSimilarity([], []));
    }

    public function testCalculateScoreNormalizesWeightedFields(): void
    {
        $e1 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'status' => 'finished', 'tags' => ['action']];
        $e2 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'status' => 'finished', 'tags' => ['action']];

        $weights = ['title' => 20, 'type' => 15, 'year' => 10, 'status' => 12, 'tags' => 18];
        // All fields match exactly -> score = 100
        $this->assertSame(100.0, MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights));
    }

    public function testCalculateScorePartialMatchLessThanPerfect(): void
    {
        $e1 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'tags' => ['action', 'sci-fi']];
        $e2 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 2001, 'tags' => ['action']];

        $weights = ['title' => 20, 'type' => 15, 'year' => 10, 'tags' => 18];
        // title=1, type=1, year=0, tags=0.5
        $expected = (1 * 20 + 1 * 15 + 0 * 10 + 0.5 * 18) / 63 * 100;
        $this->assertSame(round($expected, 2), round(MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights), 2));
    }

    public function testMissingFieldsScoreZeroWithNullVsPresent(): void
    {
        $e1 = ['title' => 'A', 'year' => 1998];
        $e2 = ['title' => 'A', 'year' => null];
        $weights = ['title' => 10, 'year' => 10];
        // title matches, year null vs present -> year partial = 0
        $this->assertSame(50.0, MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights));
    }
}
