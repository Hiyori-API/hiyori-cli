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

    public function testShortStrippedFragmentDoesNotCountAsSynonym(): void
    {
        // "殺せんせーQ！" and "ポップQ" both normalize to the bare "q". That
        // fragment must not be treated as a synonym hit between them.
        $sim = MetadataFuzzyStrategy::titleSimilarity(
            'Koro-sensei Q!',
            'Pop in Q',
            ['romaji' => 'Koro-sensei Q!', 'english' => null, 'native' => '殺せんせーQ！'],
            ['Poppin Q', 'file(N):project PQ', 'ポップQ'],
        );
        $this->assertSame(0.0, $sim);
    }

    public function testTitleGuardRejectsUnrelatedCandidateEvenWhenMetadataAgrees(): void
    {
        // Every comparable field agrees, so the weighted score clears the
        // threshold and the TITLE GUARD is what has to reject the candidate.
        // (A field that is null on both sides no longer counts as a match, so
        // the fixture has to supply season and tags to keep that premise.)
        $entry = ['title' => 'Sonic X Season 2', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'season' => 'Fall', 'status' => 'finished', 'tags' => ['action']];
        $candidate = ['title' => 'Mai-Otome', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'season' => 'Fall', 'status' => 'finished', 'tags' => ['action']];

        $weights = MetadataFuzzyStrategy::DEFAULT_WEIGHTS;
        $this->assertGreaterThanOrEqual(70.0, MetadataFuzzyStrategy::calculateScore($entry, $candidate, $weights));

        $match = MetadataFuzzyStrategy::match($entry, [$candidate], $weights, 70.0);
        $this->assertNull($match['best']);
        $this->assertSame(1, $match['rejected_title']);
    }

    public function testTitleGuardDisabledLetsLegacyBehaviourThrough(): void
    {
        $entry = ['title' => 'Sonic X Season 2', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'season' => 'Fall', 'status' => 'finished', 'tags' => ['action']];
        $candidate = ['title' => 'Mai-Otome', 'type' => 'tv', 'year' => 2005, 'episodes' => 26, 'season' => 'Fall', 'status' => 'finished', 'tags' => ['action']];

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

    /**
     * A short title that merely starts, or sits inside, a longer one is not a
     * match. This used to score 0.75+ via a bare str_contains(), and it merged
     * eight unrelated 2003 manga into MAL "Gaku" at 92-96 % confidence — which
     * failed the release gate on duplicate mal_manga.
     */
    public function testShortPrefixIsNotContainment(): void
    {
        $this->assertLessThan(
            MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR,
            MetadataFuzzyStrategy::titleSimilarity('Gakuen Tengoku', 'Gaku', [], []),
        );
        $this->assertLessThan(
            MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR,
            MetadataFuzzyStrategy::titleSimilarity('Asagaya Kusarezu Gakuen', 'Gaku', [], []),
        );
    }

    public function testInternalSubstringIsNotContainment(): void
    {
        // "rip" sits inside "trippers".
        $this->assertLessThan(
            MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR,
            MetadataFuzzyStrategy::titleSimilarity('R.I.P.', 'BAD TRIPPERS', [], []),
        );
    }

    public function testContainmentStillWorksOnWholeWords(): void
    {
        $this->assertGreaterThan(
            MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR,
            MetadataFuzzyStrategy::titleSimilarity('Berserk', 'Berserk: The Prototype', [], []),
        );
        $this->assertGreaterThan(
            MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR,
            MetadataFuzzyStrategy::titleSimilarity('One Piece', 'One Piece Film: Red', [], []),
        );
    }

    /**
     * A field that is absent on both sides is missing evidence, not a match.
     *
     * fieldSimilarity() answers 1.0 for two nulls, which handed every manga
     * 42.6 free points (episodes 26.2 + season 16.4 are null for all of them),
     * so any two manga from the same year cleared the threshold on metadata
     * alone — "Omorashi Riko-chan" merged with "Kurokami" at 96 %.
     */
    public function testFieldsMissingOnBothSidesDoNotCountAsMatches(): void
    {
        $weights = MetadataFuzzyStrategy::DEFAULT_WEIGHTS;
        $manga = static fn (string $title): array => [
            'title' => $title,
            'type' => 'manga',
            'status' => 'finished',
            'year' => 2004,
            'episodes' => null,
            'season' => null,
            'tags' => [],
        ];

        $score = MetadataFuzzyStrategy::calculateScore(
            $manga('Omorashi Riko-chan'),
            $manga('Kurokami'),
            $weights,
        );

        $this->assertLessThan(70, $score, 'unrelated manga from the same year must not clear the threshold');
    }

    public function testIdenticalEntriesStillScoreOneHundred(): void
    {
        $weights = MetadataFuzzyStrategy::DEFAULT_WEIGHTS;
        $entry = [
            'title' => 'Berserk',
            'type' => 'manga',
            'status' => 'finished',
            'year' => 1989,
            'episodes' => null,
            'season' => null,
            'tags' => ['action'],
        ];

        $this->assertSame(100.0, MetadataFuzzyStrategy::calculateScore($entry, $entry, $weights));
    }

    public function testAnimeScoringIsUnaffectedByTheChange(): void
    {
        $weights = MetadataFuzzyStrategy::DEFAULT_WEIGHTS;
        $anime = static fn (string $title): array => [
            'title' => $title,
            'type' => 'tv',
            'status' => 'finished',
            'year' => 1998,
            'episodes' => 26,
            'season' => 'Fall',
            'tags' => ['space'],
        ];

        $this->assertSame(100.0, MetadataFuzzyStrategy::calculateScore($anime('Cowboy Bebop'), $anime('Cowboy Bebop'), $weights));
    }

    /**
     * Manga are compared on the fields manga have.
     *
     * Manga have no episodes and no seasons, so the anime weights handed 42.6
     * of the 100 points to any two manga from the same year. All three manga
     * sources populate chapters and volumes, so those take the same slots.
     */
    public function testMangaWeightsUseChaptersAndVolumes(): void
    {
        $weights = MetadataFuzzyStrategy::weightsForKind('anilist_manga');

        $this->assertArrayHasKey('chapters', $weights);
        $this->assertArrayHasKey('volumes', $weights);
        $this->assertArrayNotHasKey('episodes', $weights);
        $this->assertArrayNotHasKey('season', $weights);
        $this->assertSame(MetadataFuzzyStrategy::MANGA_WEIGHTS, $weights);
        $this->assertSame(MetadataFuzzyStrategy::DEFAULT_WEIGHTS, MetadataFuzzyStrategy::weightsForKind('anilist'));
    }

    public function testMangaScoreSeparatesWorksOfDifferentLength(): void
    {
        $weights = MetadataFuzzyStrategy::MANGA_WEIGHTS;
        $manga = static fn (int $chapters, int $volumes): array => [
            'title' => 'Some Title',
            'type' => 'manga',
            'status' => 'finished',
            'year' => 2004,
            'chapters' => $chapters,
            'volumes' => $volumes,
            'tags' => [],
        ];

        $this->assertLessThan(
            70,
            MetadataFuzzyStrategy::calculateScore($manga(100, 5), $manga(12, 1), $weights),
        );
    }
}
