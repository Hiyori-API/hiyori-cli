<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Pipeline\StageOutput;
use PHPUnit\Framework\TestCase;

final class StageOutputTest extends TestCase
{
    public function testJsonExtractsAJsonObject(): void
    {
        $this->assertSame(['ok' => true], StageOutput::json("{\"ok\":true}\n"));
    }

    public function testJsonReturnsNullForProse(): void
    {
        $this->assertNull(StageOutput::json("No pending migrations — DB is up to date.\n"));
        $this->assertNull(StageOutput::json(''));
    }

    public function testJsonReturnsNullForMalformedJson(): void
    {
        $this->assertNull(StageOutput::json('{not json}'));
    }

    public function testCombineCoverageParsesTheCoverageLine(): void
    {
        $line = 'Coverage: 15,000 of 15,015 base rows covered (99.9%) — merged rows: 12,345, '
            . 'single-source rows: 2,655, unmatched rows: 0, skipped rows (no base reference id): 15';

        $this->assertSame([
            'covered' => 15000,
            'examined' => 15015,
            'coverage_percent' => 99.9,
            'merged' => 12345,
            'passthrough' => 2655,
            'unmatched' => 0,
            'unresolved_base_id' => 15,
        ], StageOutput::combineCoverage($line));
    }

    public function testCombineCoverageReturnsNullWhenTheLineIsMissing(): void
    {
        $this->assertNull(StageOutput::combineCoverage('The combiner returned no coverage statistics.'));
    }

    public function testExcerptTruncatesLongOutput(): void
    {
        $excerpt = StageOutput::excerpt(str_repeat('a', 100), 10);

        $this->assertStringStartsWith('aaaaaaaaaa', $excerpt);
        $this->assertStringContainsString('truncated', $excerpt);
    }

    public function testExcerptKeepsShortOutputWhole(): void
    {
        $this->assertSame('short', StageOutput::excerpt("  short\n"));
    }
}
