<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Ml\FeatureExtractor;
use Hiyori\Service\Ml\FeatureSetMismatchException;
use Hiyori\Service\Ml\LogisticModel;
use Hiyori\Service\Ml\LogisticRegressionTrainer;
use PHPUnit\Framework\TestCase;

/**
 * The manga half of the ML feature vector, and the feature-set contract
 * between the extractor and the stored model.
 *
 * The feature list is baked into the model file (`mean`, `std`, `weights` are
 * positional, and the feature list says which weight belongs to which
 * feature), so the two must agree exactly. A mismatch has to be a loud
 * failure: a silently mis-mapped score merges unrelated works.
 */
final class MlFeatureSetTest extends TestCase
{
    /** The feature list as it was before manga support was added. */
    private const ANIME_FEATURES = [
        'exact_norm',
        'jaro',
        'token_jaccard',
        'containment',
        'synonym_overlap',
        'type_match',
        'episodes_match',
        'status_match',
        'season_match',
        'year_similarity',
        'tags_jaccard',
        'ref_overlap',
    ];

    public function testMangaCountFeaturesArePartOfTheFeatureSet(): void
    {
        $this->assertContains('chapters_match', FeatureExtractor::FEATURES);
        $this->assertContains('volumes_match', FeatureExtractor::FEATURES);
        $this->assertCount(count(self::ANIME_FEATURES) + 2, FeatureExtractor::FEATURES);
    }

    public function testTheAnimeFeaturesKeepTheirIndex(): void
    {
        // Append only: inserting a feature would re-map every weight in a
        // stored model, because the weights are positional.
        $this->assertSame(self::ANIME_FEATURES, array_slice(FeatureExtractor::FEATURES, 0, count(self::ANIME_FEATURES)));
    }

    public function testMangaCountFeaturesUseTheGradedComparison(): void
    {
        $extractor = new FeatureExtractor();

        $exact = $extractor->extract(
            ['title' => 'Berserk', 'chapters' => 374, 'volumes' => 41],
            ['title' => 'Berserk', 'chapters' => 374, 'volumes' => 41],
        );
        $this->assertSame(1.0, $exact['chapters_match']);
        $this->assertSame(1.0, $exact['volumes_match']);

        // A near miss still scores highly: two sources rarely agree on the
        // last chapter of a series.
        $near = $extractor->extract(
            ['title' => 'Berserk', 'chapters' => 374, 'volumes' => 41],
            ['title' => 'Berserk', 'chapters' => 370, 'volumes' => 40],
        );
        $this->assertGreaterThan(0.9, $near['chapters_match']);
        $this->assertLessThan(1.0, $near['chapters_match']);
        $this->assertGreaterThan(0.9, $near['volumes_match']);

        // A completely different length is a disagreement.
        $far = $extractor->extract(
            ['title' => 'Berserk', 'chapters' => 374, 'volumes' => 41],
            ['title' => 'Berserk', 'chapters' => 12, 'volumes' => 1],
        );
        $this->assertLessThan(0.1, $far['chapters_match']);
    }

    public function testAnUnknownCountIsNotAMatch(): void
    {
        $extractor = new FeatureExtractor();

        // Zero means "unknown", not "zero chapters": it must never score as
        // a match, and it must not drag a known count down either.
        $unknown = $extractor->extract(
            ['title' => 'Berserk', 'chapters' => 0, 'volumes' => null],
            ['title' => 'Berserk', 'chapters' => 120, 'volumes' => 12],
        );
        $this->assertSame(0.0, $unknown['chapters_match']);
        $this->assertSame(0.0, $unknown['volumes_match']);

        $missing = $extractor->extract(
            ['title' => 'Berserk'],
            ['title' => 'Berserk', 'chapters' => 120, 'volumes' => 12],
        );
        $this->assertSame(0.0, $missing['chapters_match']);
        $this->assertSame(0.0, $missing['volumes_match']);
    }

    public function testMangaPairsGetSignalTheAnimeFeaturesCannotCarry(): void
    {
        $extractor = new FeatureExtractor();

        // Two manga with the same title, type and year but a different
        // length: `episodes`/`season` are null for both, so the anime
        // features are constant and the vector must still differ.
        $agree = $extractor->extract(
            ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'episodes' => null, 'season' => null, 'chapters' => 12, 'volumes' => 1],
            ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'episodes' => null, 'season' => null, 'chapters' => 12, 'volumes' => 1],
        );
        $disagree = $extractor->extract(
            ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'episodes' => null, 'season' => null, 'chapters' => 12, 'volumes' => 1],
            ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'episodes' => null, 'season' => null, 'chapters' => 374, 'volumes' => 41],
        );

        // The anime features carry no signal at all for manga.
        $this->assertSame(0.0, $agree['episodes_match']);
        $this->assertSame(1.0, $agree['season_match']);
        $this->assertSame($agree['episodes_match'], $disagree['episodes_match']);
        $this->assertSame($agree['season_match'], $disagree['season_match']);

        // The manga features do.
        $this->assertSame(1.0, $agree['chapters_match']);
        $this->assertSame(1.0, $agree['volumes_match']);
        $this->assertLessThan(0.1, $disagree['chapters_match']);
        $this->assertLessThan(0.1, $disagree['volumes_match']);

        $this->assertNotSame(
            $extractor->vector(
                ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'chapters' => 12, 'volumes' => 1],
                ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'chapters' => 12, 'volumes' => 1],
            ),
            $extractor->vector(
                ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'chapters' => 12, 'volumes' => 1],
                ['title' => 'Berserk', 'type' => 'manga', 'year' => 1989, 'chapters' => 374, 'volumes' => 41],
            ),
        );
    }

    public function testTheVectorHasOneValuePerFeature(): void
    {
        $extractor = new FeatureExtractor();
        $vector = $extractor->vector(
            ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26],
            ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26],
        );

        $this->assertCount(count(FeatureExtractor::FEATURES), $vector);
        $this->assertSame(1.0, $vector[array_search('exact_norm', FeatureExtractor::FEATURES, true)]);
    }

    public function testAStaleModelIsRefusedOnLoad(): void
    {
        // The model that shipped before manga support: 12 features, and a
        // version that says `ml:train` produced it.
        $path = $this->writeModel([
            'model' => 'logreg',
            'version' => 'logreg-2026.10.16.122621',
            'features' => self::ANIME_FEATURES,
            'mean' => array_fill(0, count(self::ANIME_FEATURES), 0.0),
            'std' => array_fill(0, count(self::ANIME_FEATURES), 1.0),
            'weights' => array_fill(0, count(self::ANIME_FEATURES), 1.0),
            'bias' => 0.0,
        ]);

        $this->expectException(FeatureSetMismatchException::class);
        $this->expectExceptionMessage('feature-set mismatch');
        LogisticModel::load($path);
    }

    public function testTheStaleModelMessageNamesBothFeatureSets(): void
    {
        $path = $this->writeModel([
            'model' => 'logreg',
            'version' => 'logreg-2026.10.16.122621',
            'features' => self::ANIME_FEATURES,
            'mean' => array_fill(0, count(self::ANIME_FEATURES), 0.0),
            'std' => array_fill(0, count(self::ANIME_FEATURES), 1.0),
            'weights' => array_fill(0, count(self::ANIME_FEATURES), 1.0),
            'bias' => 0.0,
        ]);

        try {
            LogisticModel::load($path);
            $this->fail('Loading a stale model must not be silent.');
        } catch (FeatureSetMismatchException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('chapters_match', $message);
            $this->assertStringContainsString('volumes_match', $message);
            $this->assertStringContainsString('ml:train', $message);
        }
    }

    public function testAModelOnTheCurrentFeatureSetLoads(): void
    {
        $n = count(FeatureExtractor::FEATURES);
        $path = $this->writeModel([
            'model' => 'logreg',
            'version' => 'logreg-2026.10.19.000000',
            'feature_set_version' => FeatureExtractor::FEATURE_SET_VERSION,
            'features' => FeatureExtractor::FEATURES,
            'mean' => array_fill(0, $n, 0.0),
            'std' => array_fill(0, $n, 1.0),
            'weights' => array_fill(0, $n, 1.0),
            'bias' => 0.0,
        ]);

        $model = LogisticModel::load($path);
        $this->assertNotNull($model);
        $this->assertTrue($model->hasFeatureSet());
        $this->assertSame(FeatureExtractor::FEATURE_SET_VERSION, $model->featureSetVersion());
    }

    public function testPredictRefusesAPositionalVectorOfTheWrongLength(): void
    {
        $model = $this->modelFor(self::ANIME_FEATURES);
        $extractor = new FeatureExtractor();

        $this->expectException(FeatureSetMismatchException::class);
        $model->predict($extractor->vector(['title' => 'Berserk'], ['title' => 'Berserk']));
    }

    public function testPredictRefusesAMapWithFeaturesTheModelDoesNotKnow(): void
    {
        $model = $this->modelFor(self::ANIME_FEATURES);
        $extractor = new FeatureExtractor();

        $features = $extractor->extract(['title' => 'Berserk'], ['title' => 'Berserk']);

        $this->expectException(FeatureSetMismatchException::class);
        $model->predict($features);
    }

    public function testPredictStillScoresAVectorThatLinesUp(): void
    {
        $n = count(FeatureExtractor::FEATURES);
        $model = $this->modelFor(FeatureExtractor::FEATURES);
        $extractor = new FeatureExtractor();

        $score = $model->predict($extractor->vector(['title' => 'Berserk'], ['title' => 'Berserk']));
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(1.0, $score);
        $this->assertCount($n, FeatureExtractor::FEATURES);
    }

    public function testTheTrainerRefusesRowsFromAnotherFeatureSet(): void
    {
        $trainer = new LogisticRegressionTrainer();

        // Rows exported before manga support: 12 values, not 14. Padding
        // them with zeros would train a model that believes the two manga
        // features are always absent.
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['vector' => array_fill(0, count(self::ANIME_FEATURES), 0.5), 'label' => 1];
        }

        $this->expectException(FeatureSetMismatchException::class);
        $this->expectExceptionMessage('ml:export-pairs');
        $trainer->train($rows);
    }

    public function testTheTrainerStampsTheFeatureSetVersion(): void
    {
        $trainer = new LogisticRegressionTrainer();
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['vector' => array_fill(0, count(FeatureExtractor::FEATURES), 0.5), 'label' => 1];
        }

        $model = $trainer->train($rows, ['epochs' => 5]);
        $this->assertSame(FeatureExtractor::FEATURE_SET_VERSION, $model['feature_set_version']);
        $this->assertSame(FeatureExtractor::FEATURES, $model['features']);

        // The model it produces loads and scores without complaint.
        $path = $this->writeModel($model);
        $loaded = LogisticModel::load($path);
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->hasFeatureSet());
    }

    public function testTheStoredProductionModelIsRefusedWhileItIsStale(): void
    {
        $path = dirname(__DIR__, 2) . '/storage/state/ml-model.json';
        if (!is_file($path)) {
            $this->markTestSkipped('No stored model in this checkout.');
        }

        try {
            $model = LogisticModel::load($path);
            // A model that does match the current feature set is fine.
            $this->assertNotNull($model);
            $this->assertTrue($model->hasFeatureSet());
        } catch (FeatureSetMismatchException $e) {
            $this->assertStringContainsString('feature-set mismatch', $e->getMessage());
        }
    }

    /**
     * A model that carries a given feature list, written to a temp file.
     *
     * @param list<string> $features
     */
    private function modelFor(array $features): LogisticModel
    {
        $n = count($features);
        $path = $this->writeModel([
            'model' => 'logreg',
            'version' => 'test-1',
            'features' => $features,
            'mean' => array_fill(0, $n, 0.0),
            'std' => array_fill(0, $n, 1.0),
            'weights' => array_fill(0, $n, 1.0),
            'bias' => 0.0,
        ]);

        $model = LogisticModel::load($path);
        $this->assertNotNull($model);

        return $model;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function writeModel(array $model): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mlmodel') . '.json';
        file_put_contents($path, json_encode($model));

        return $path;
    }
}
