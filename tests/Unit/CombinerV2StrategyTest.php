<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\Strategy\FellegiSunterStrategy;
use Hiyori\Service\Combiner\Strategy\SemanticRerankStrategy;
use Hiyori\Service\Ml\EmbeddingService;
use Hiyori\Service\StrategyConfigurationFactory;
use PHPUnit\Framework\TestCase;

final class CombinerV2StrategyTest extends TestCase
{
    public function testFellegiSunterPerfectPairScores100(): void
    {
        $entry = [
            'title' => 'Cowboy Bebop',
            'synonyms' => ['Cowboy Bebop'],
            'type' => 'tv',
            'episodes' => 26,
            'status' => 'finished',
            'season' => 'spring',
            'year' => 1998,
            'tags' => ['action', 'sci-fi'],
        ];

        $score = FellegiSunterStrategy::score(
            $entry,
            $entry,
            FellegiSunterStrategy::DEFAULT_M,
            FellegiSunterStrategy::DEFAULT_U,
            0.85,
        );

        $this->assertSame(100.0, $score);
    }

    public function testFellegiSunterPenalisesDisagreement(): void
    {
        $left = ['title' => 'Cowboy Bebop', 'type' => 'tv', 'year' => 1998, 'episodes' => 26];
        $right = ['title' => 'One Piece', 'type' => 'tv', 'year' => 1999, 'episodes' => 1000];

        $score = FellegiSunterStrategy::score(
            $left,
            $right,
            FellegiSunterStrategy::DEFAULT_M,
            FellegiSunterStrategy::DEFAULT_U,
            0.85,
        );

        $this->assertLessThan(60.0, $score);
    }

    public function testSemanticBlendRespectsWeights(): void
    {
        $this->assertSame(80.0, SemanticRerankStrategy::blend(90.0, 50.0, 0.75, 0.25));
        $this->assertSame(90.0, SemanticRerankStrategy::blend(90.0, 50.0, 1.0, 0.0));
        $this->assertSame(50.0, SemanticRerankStrategy::blend(90.0, 50.0, 0.0, 1.0));
    }

    public function testV2StrategiesAreRegisteredByName(): void
    {
        foreach (['graph_merge', 'fellegi_sunter', 'semantic_rerank'] as $name) {
            $factory = StrategyConfigurationFactory::create()->setStrategy($name);
            $this->assertSame($name, $factory->get()::NAME, $name);
        }
    }

    public function testEmbeddingLocalFallbackIsDeterministicAndNormalized(): void
    {
        $service = new EmbeddingService(64, null, sys_get_temp_dir() . '/hiyori-test-embeddings.json');
        $a = $service->embed('Cowboy Bebop');
        $b = $service->embed('Cowboy Bebop');
        $c = $service->embed('One Piece');

        $this->assertCount(64, $a);
        $this->assertSame($a, $b);
        $this->assertGreaterThan(0.99, EmbeddingService::cosine($a, $b));
        $this->assertLessThan(0.5, EmbeddingService::cosine($a, $c));
    }

    public function testEmbeddingEntryAveragesTitleAndSynonyms(): void
    {
        $service = new EmbeddingService(64, null, sys_get_temp_dir() . '/hiyori-test-embeddings-2.json');
        $vector = $service->embedEntry(['title' => 'Bebop', 'synonyms' => ['Cowboy Bebop']]);

        $this->assertCount(64, $vector);
        $this->assertSame(1.0, round(EmbeddingService::cosine($vector, $vector), 6));
    }
}
