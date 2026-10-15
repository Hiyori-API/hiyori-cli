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
        $this->assertEqualsWithDelta(1 / 3, $sim, 0.0001);
        $this->assertSame(1.0, MetadataFuzzyStrategy::tagsSimilarity(['a'], ['a']));
        $this->assertSame(0.0, MetadataFuzzyStrategy::tagsSimilarity(['a'], ['b']));
        $this->assertSame(1.0, MetadataFuzzyStrategy::tagsSimilarity([], []));
    }

    public function testYearSimilarityGraded(): void
    {
        $this->assertSame(1.0, MetadataFuzzyStrategy::yearSimilarity(1998, 1998));
        $this->assertSame(0.8, MetadataFuzzyStrategy::yearSimilarity(1998, 1999));
        $this->assertSame(0.6, MetadataFuzzyStrategy::yearSimilarity(1998, 2000));
        $this->assertSame(0.0, MetadataFuzzyStrategy::yearSimilarity(1998, 2003));
        $this->assertSame(0.0, MetadataFuzzyStrategy::yearSimilarity(null, 1998));
        $this->assertSame(0.0, MetadataFuzzyStrategy::yearSimilarity(1998, null));
    }

    public function testJaroWinklerIdenticalAndDifferent(): void
    {
        $this->assertSame(1.0, MetadataFuzzyStrategy::jaroWinkler('cowboy bebop', 'Cowboy Bebop'));
        $this->assertSame(0.0, MetadataFuzzyStrategy::jaroWinkler('', 'x'));
        $this->assertGreaterThan(0.8, MetadataFuzzyStrategy::jaroWinkler('trigun', 'trigun?'));
        $this->assertLessThan(0.5, MetadataFuzzyStrategy::jaroWinkler('naruto', 'one piece'));
    }

    public function testTypeFuzzyFallbackHelpsNearMatches(): void
    {
        $e1 = ['title' => 'X', 'type' => 'tv_short'];
        $e2 = ['title' => 'X', 'type' => 'tv'];
        $weights = ['title' => 10, 'type' => 10];
        $score = MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights);
        $this->assertGreaterThan(50.0, $score);
        $this->assertLessThan(100.0, $score);
    }

    public function testCalculateScoreNormalizesWeightedFields(): void
    {
        $e1 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'status' => 'finished', 'tags' => ['action']];
        $e2 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'status' => 'finished', 'tags' => ['action']];

        $weights = ['title' => 20, 'type' => 15, 'year' => 10, 'status' => 12, 'tags' => 18];
        $this->assertSame(100.0, MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights));
    }

    public function testCalculateScorePartialMatchLessThanPerfect(): void
    {
        $e1 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'tags' => ['action', 'sci-fi']];
        $e2 = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 2001, 'tags' => ['action']];

        $weights = ['title' => 20, 'type' => 15, 'year' => 10, 'tags' => 18];
        // year 1998 vs 2001 -> graded yearSimilarity = 1 - 3/5 = 0.4
        $expected = (1 * 20 + 1 * 15 + 0.4 * 10 + 0.5 * 18) / 63 * 100;
        $this->assertSame(round($expected, 2), round(MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights), 2));
    }

    public function testMissingFieldsScoreZeroWithNullVsPresent(): void
    {
        $e1 = ['title' => 'A', 'year' => 1998];
        $e2 = ['title' => 'A', 'year' => null];
        $weights = ['title' => 10, 'year' => 10];
        $this->assertSame(50.0, MetadataFuzzyStrategy::calculateScore($e1, $e2, $weights));
    }

    public function testEmptySynonymsDoNotCreateFalseMatch(): void
    {
        // Regression: '' === '' in the synonym intersection used to return 0.9
        // for completely unrelated titles whenever both sides carried a blank
        // synonym (sources routinely do).
        $sim = MetadataFuzzyStrategy::titleSimilarity(
            'HUNTER×HUNTER (2011)',
            'Fate/Zero',
            ['Hunter x Hunter (2011)', 'HxH', ''],
            ['Fate Zero', ''],
        );
        $this->assertLessThan(0.3, $sim);
    }

    public function testRealSynonymOverlapStillScoresHigh(): void
    {
        $sim = MetadataFuzzyStrategy::titleSimilarity(
            'Shingeki no Kyojin',
            'Attack on Titan',
            ['Attack on Titan'],
            ['Shingeki no Kyojin'],
        );
        $this->assertSame(0.9, $sim);
    }

    public function testTitleGuardRejectsUnrelatedCandidateEvenWhenMetadataAgrees(): void
    {
        $entry = ['title' => 'Sonic X Season 2', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'status' => 'finished'];
        // Same type/year/episodes as the base but an unrelated title: without
        // the guard this would score ~72 (>70) and merge.
        $candidate = ['title' => 'Mai-Otome', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'status' => 'finished'];

        $weights = MetadataFuzzyStrategy::DEFAULT_WEIGHTS;
        $this->assertGreaterThanOrEqual(70.0, MetadataFuzzyStrategy::calculateScore($entry, $candidate, $weights));

        $match = MetadataFuzzyStrategy::match($entry, [$candidate], $weights, 70.0);
        $this->assertNull($match['best']);
        $this->assertSame(1, $match['rejected_title']);
    }

    public function testTitleGuardDisabledLetsLegacyBehaviourThrough(): void
    {
        $entry = ['title' => 'Sonic X Season 2', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'status' => 'finished'];
        $candidate = ['title' => 'Mai-Otome', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'status' => 'finished'];

        $match = MetadataFuzzyStrategy::match($entry, [$candidate], MetadataFuzzyStrategy::DEFAULT_WEIGHTS, 70.0, true, 1, false, 0.0);
        $this->assertNotNull($match['best']);
        $this->assertSame(0, $match['rejected_title']);
    }

    public function testTitleGuardKeepsLegitimateMatch(): void
    {
        $entry = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26, 'status' => 'finished'];
        $candidate = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26, 'status' => 'finished'];

        $match = MetadataFuzzyStrategy::match($entry, [$candidate], MetadataFuzzyStrategy::DEFAULT_WEIGHTS, 70.0);
        $this->assertNotNull($match['best']);
        $this->assertSame(0, $match['rejected_title']);
    }
}
