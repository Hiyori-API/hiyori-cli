<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\CombineStats;
use Hiyori\Service\StrategyConfigurationFactory;
use PHPUnit\Framework\TestCase;

/**
 * The coverage contract that `hiyori combine` gates on. These are pure
 * arithmetic checks over fabricated counters: a run that drops base rows must
 * be distinguishable from a healthy one without touching MongoDB.
 */
final class CombineStatsTest extends TestCase
{
    public function testCoverageIsWrittenOverExamined(): void
    {
        $stats = new CombineStats('metadata_fuzzy', 'anilist', 1000, 600, 100, 200, 100);

        $this->assertSame(700, $stats->written());
        $this->assertSame(70.0, $stats->coverage());
        $this->assertSame('70.0', $stats->coveragePercent());
    }

    public function testCountersPartitionTheBaseRows(): void
    {
        // Every examined row is either merged, kept single-source, unmatched or
        // keyless; nothing falls outside those buckets.
        $stats = CombineStats::forRun('metadata_fuzzy', 'anilist', 15_015, 8_894, 0, 6_000);

        $this->assertSame(8_894, $stats->written());
        $this->assertSame(121, $stats->unmatched, 'unmatched is examined minus written minus keyless');
        $this->assertSame(
            $stats->examined,
            $stats->merged + $stats->passthrough + $stats->unmatched + $stats->unresolvedBaseId,
        );
    }

    public function testTheLoggedSilentLossBecomesVisible(): void
    {
        // storage/logs/rebuild2.log: 8,998 documents from a 15,015-row base,
        // exit 0. Before this contract that run was indistinguishable from a
        // complete one.
        $stats = CombineStats::forRun('metadata_fuzzy', 'anilist', 15_015, 8_998, 0, 0);

        $this->assertSame('59.9', $stats->coveragePercent());
        $this->assertLessThan(60.0, $stats->coverage());
        $this->assertTrue($stats->failsCoverageGate(60));
    }

    public function testFailsBelowTheFloorAndPassesAtOrAboveIt(): void
    {
        $atFloor = CombineStats::forRun('metadata_fuzzy', 'anilist', 4, 2, 0, 0);   // 50.0 %
        $belowFloor = CombineStats::forRun('metadata_fuzzy', 'anilist', 4, 1, 0, 0); // 25.0 %

        $this->assertFalse($atFloor->failsCoverageGate(50), 'exactly at the floor passes');
        $this->assertTrue($belowFloor->failsCoverageGate(50));
    }

    public function testEmptyBaseNeverPasses(): void
    {
        $stats = CombineStats::forRun('metadata_fuzzy', 'anilist', 0, 0, 0, 0);

        $this->assertSame(0.0, $stats->coverage());
        $this->assertTrue($stats->failsCoverageGate(40), 'a run that examined nothing is a failure');
        $this->assertTrue($stats->failsCoverageGate(0), 'disabling the floor must not let an empty run pass');
    }

    public function testZeroFloorDisablesThePercentageComparisonOnly(): void
    {
        $stats = CombineStats::forRun('relational_mapping', 'anilist', 100, 10, 0, 0);

        $this->assertFalse($stats->failsCoverageGate(0));
        $this->assertTrue($stats->failsCoverageGate(40));
    }

    public function testSingleSourceRecordsCountAsCoverage(): void
    {
        // Same base: one merged, one kept by --passthrough. Both rows are
        // represented by a document.
        $stats = CombineStats::forRun('metadata_fuzzy', 'anilist', 2, 1, 1, 0);

        $this->assertSame(2, $stats->written());
        $this->assertSame(100.0, $stats->coverage());
        $this->assertFalse($stats->failsCoverageGate(100));
    }

    public function testToArrayExposesTheContractForJsonConsumers(): void
    {
        $stats = new CombineStats('metadata_fuzzy', 'anilist', 1000, 600, 100, 200, 100);

        $this->assertSame([
            'strategy' => 'metadata_fuzzy',
            'base' => 'anilist',
            'examined' => 1000,
            'written' => 700,
            'merged' => 600,
            'passthrough' => 100,
            'unmatched' => 200,
            'unresolved_base_id' => 100,
            'coverage_percent' => 70.0,
        ], $stats->toArray());
    }

    public function testEveryRegisteredStrategyReportsCombineStats(): void
    {
        foreach (StrategyConfigurationFactory::AVAILABLE_STRATEGIES as $class) {
            $returnType = (new \ReflectionMethod($class, 'process'))->getReturnType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $returnType, $class . '::process() must declare a return type');
            $this->assertSame(CombineStats::class, $returnType->getName(), $class . '::process() must return the coverage contract');
        }
    }
}
