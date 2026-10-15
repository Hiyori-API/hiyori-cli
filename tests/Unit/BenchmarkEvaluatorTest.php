<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Ml\BenchmarkEvaluator;
use PHPUnit\Framework\TestCase;

final class BenchmarkEvaluatorTest extends TestCase
{
    public function testSweepComputesConfusionPerThreshold(): void
    {
        $scores = [90.0, 80.0, 75.0, 20.0, 10.0];
        $labels = [1, 1, 0, 0, 0];

        $sweep = BenchmarkEvaluator::sweep($scores, $labels, [70.0, 85.0]);

        $this->assertCount(2, $sweep);

        $at70 = $sweep[0];
        $this->assertSame(70.0, $at70['threshold']);
        $this->assertSame(2, $at70['tp']);
        $this->assertSame(1, $at70['fp']);
        $this->assertSame(0, $at70['fn']);
        $this->assertSame(3, $at70['predicted']);
        $this->assertSame(0.6667, $at70['precision']);
        $this->assertSame(1.0, $at70['recall']);
        $this->assertSame(0.8, $at70['f1']);

        $at85 = $sweep[1];
        $this->assertSame(1, $at85['tp']);
        $this->assertSame(0, $at85['fp']);
        $this->assertSame(1, $at85['fn']);
        $this->assertSame(0.6667, $at85['f1']);
    }

    public function testBestPicksHighestF1ThenPrecision(): void
    {
        $rows = [
            ['threshold' => 60.0, 'f1' => 0.7, 'precision' => 0.8, 'recall' => 0.6],
            ['threshold' => 70.0, 'f1' => 0.9, 'precision' => 0.85, 'recall' => 0.9],
            ['threshold' => 80.0, 'f1' => 0.9, 'precision' => 0.95, 'recall' => 0.8],
        ];

        $best = BenchmarkEvaluator::best($rows);
        $this->assertNotNull($best);
        $this->assertSame(80.0, $best['threshold']);
    }

    public function testBestReturnsNullForEmptySweep(): void
    {
        $this->assertNull(BenchmarkEvaluator::best([]));
    }
}
