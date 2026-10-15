<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\Strategy\MetadataMlStrategy;
use Hiyori\Service\Ml\FeatureExtractor;
use Hiyori\Service\Ml\LogisticModel;
use Hiyori\Service\Ml\LogisticRegressionTrainer;
use Hiyori\Service\StrategyConfigurationFactory;
use PHPUnit\Framework\TestCase;

final class MlLevel0Test extends TestCase
{
    public function testFeatureVectorHasStableOrderAndRange(): void
    {
        $extractor = new FeatureExtractor();
        $features = $extractor->extract(
            ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26, 'reference_ids' => ['mal' => '1']],
            ['title' => 'Ｃｏｗｂｏｙ Ｂｅｂｏｐ', 'type' => 'tv', 'year' => 1998, 'episodes' => 26, 'reference_ids' => ['anilist' => '2']],
        );

        foreach (FeatureExtractor::FEATURES as $feature) {
            $this->assertArrayHasKey($feature, $features);
            $this->assertGreaterThanOrEqual(0.0, $features[$feature]);
            $this->assertLessThanOrEqual(1.0, $features[$feature]);
        }
        // NFKC folding should make the full-width variant an exact title match.
        $this->assertSame(1.0, $features['exact_norm']);

        $vector = $extractor->vector(
            ['title' => 'Cowboy Bebop'],
            ['title' => 'Cowboy Bebop'],
        );
        $this->assertCount(count(FeatureExtractor::FEATURES), $vector);
        $this->assertSame(1.0, $vector[array_search('exact_norm', FeatureExtractor::FEATURES, true)]);
    }

    public function testSharesReferenceOnlyOnIdenticalIds(): void
    {
        $this->assertTrue(FeatureExtractor::sharesReference(
            ['reference_ids' => ['mal' => '1']],
            ['reference_ids' => ['mal' => '1']],
        ));
        $this->assertFalse(FeatureExtractor::sharesReference(
            ['reference_ids' => ['mal' => '1']],
            ['reference_ids' => ['mal' => '2']],
        ));
        $this->assertFalse(FeatureExtractor::sharesReference(
            ['reference_ids' => ['mal' => '1']],
            ['reference_ids' => ['anilist' => '1']],
        ));
    }

    public function testLogisticModelLoadsAndPredictsMonotonically(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mlmodel') . '.json';
        file_put_contents($path, json_encode([
            'model' => 'logreg',
            'version' => 'test-1',
            'features' => ['exact_norm', 'jaro'],
            'mean' => [0.0, 0.0],
            'std' => [1.0, 1.0],
            'weights' => [2.0, 2.0],
            'bias' => -2.0,
        ]));

        $model = LogisticModel::load($path);
        @unlink($path);

        $this->assertNotNull($model);
        $this->assertSame('test-1', $model->version());

        $low = $model->predict(['exact_norm' => 0.0, 'jaro' => 0.0]);
        $high = $model->predict(['exact_norm' => 1.0, 'jaro' => 0.9]);
        $this->assertGreaterThan($low, $high);
        $this->assertGreaterThan(0.5, $high);
        $this->assertLessThan(0.5, $low);
    }

    public function testLogisticModelReturnsNullForMissingFile(): void
    {
        $this->assertNull(LogisticModel::load(sys_get_temp_dir() . '/definitely-not-a-model-xyz.json'));
    }

    public function testTrainerSeparatesToyDataAndIsDeterministic(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['vector' => $this->vec(['jaro' => 0.92, 'exact_norm' => 1.0, 'token_jaccard' => 0.8, 'year_similarity' => 1.0]), 'label' => 1];
            $rows[] = ['vector' => $this->vec(['jaro' => 0.15, 'exact_norm' => 0.0, 'token_jaccard' => 0.0, 'year_similarity' => 0.0]), 'label' => 0];
        }

        $trainer = new LogisticRegressionTrainer();
        $model = $trainer->train($rows, ['epochs' => 300]);

        $this->assertGreaterThanOrEqual(0.95, $model['metrics']['auc']);
        $this->assertGreaterThanOrEqual(0.9, $model['metrics']['f1']);

        $loaded = $this->loadModelFromArray($model);
        $positive = $loaded->predict($this->vec(['jaro' => 0.92, 'exact_norm' => 1.0, 'token_jaccard' => 0.8, 'year_similarity' => 1.0]));
        $negative = $loaded->predict($this->vec(['jaro' => 0.15]));

        $this->assertGreaterThan(0.5, $positive);
        $this->assertLessThan(0.5, $negative);
        $this->assertGreaterThan($negative, $positive);

        $again = $trainer->train($rows, ['epochs' => 300]);
        $this->assertSame($model['weights'], $again['weights']);
        $this->assertSame($model['bias'], $again['bias']);
    }

    public function testMetricsAndAuc(): void
    {
        $scores = [0.9, 0.8, 0.2, 0.1];
        $labels = [1.0, 1.0, 0.0, 0.0];

        $metrics = LogisticRegressionTrainer::metrics($scores, $labels, 0.5);
        $this->assertSame(4, $metrics['n']);
        $this->assertSame(1.0, $metrics['precision']);
        $this->assertSame(1.0, $metrics['recall']);
        $this->assertSame(1.0, $metrics['f1']);
        $this->assertSame(1.0, $metrics['auc']);
    }

    public function testMetadataMlStrategyIsRegistered(): void
    {
        $factory = StrategyConfigurationFactory::create()->setStrategy('metadata_ml');
        $this->assertInstanceOf(MetadataMlStrategy::class, $factory->get());
        $this->assertContains(MetadataMlStrategy::class, StrategyConfigurationFactory::AVAILABLE_STRATEGIES);
    }

    /**
     * @param array<string, float> $assoc
     *
     * @return list<float>
     */
    private function vec(array $assoc): array
    {
        $out = [];
        foreach (FeatureExtractor::FEATURES as $feature) {
            $out[] = (float) ($assoc[$feature] ?? 0.0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function loadModelFromArray(array $model): LogisticModel
    {
        $path = tempnam(sys_get_temp_dir(), 'mlmodel') . '.json';
        file_put_contents($path, json_encode($model));
        $loaded = LogisticModel::load($path);
        @unlink($path);
        $this->assertNotNull($loaded);

        return $loaded;
    }
}
