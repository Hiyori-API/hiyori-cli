<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Benchmark;
use Hiyori\Service\Ml\BenchmarkEvaluator;
use Hiyori\Service\Telemetry;
use PHPUnit\Framework\TestCase;

/**
 * The benchmark payload the dashboard renders.
 *
 * Two things matter here: the log parser must attach each "wall: 123s"
 * marker to the JSON row that follows it, and the ML report must expose the
 * best threshold row picked by the shared evaluator rather than a second
 * definition of "best".
 */
final class BenchmarkTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hiyori-benchmark-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/logs', 0o777, true);
        mkdir($this->dir . '/state', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/logs/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/state/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir . '/state');
        @rmdir($this->dir);
    }

    private function benchmark(): Benchmark
    {
        return new Benchmark(new Telemetry($this->dir . '/state'), $this->dir . '/logs', $this->dir . '/state');
    }

    public function testReadLogAttachesTheWallMarkerToTheRowThatFollowsIt(): void
    {
        $file = $this->dir . '/logs/strategy-benchmark-anime.log';
        file_put_contents($file, implode("\n", [
            'wall: 12.5s',
            '{"strategy":"metadata_fuzzy","total":10,"duplicate_keys":2}',
            'not json',
            'wall: 3s',
            '{"strategy":"metadata_ml","total":7,"duplicate_keys":0}',
        ]));

        $rows = Benchmark::readLog($file);

        $this->assertCount(2, $rows);
        $this->assertSame('metadata_fuzzy', $rows[0]['strategy']);
        $this->assertSame(12.5, $rows[0]['wall']);
        $this->assertSame(3.0, $rows[1]['wall']);
    }

    public function testReadLogIsEmptyForAMissingFile(): void
    {
        $this->assertSame([], Benchmark::readLog($this->dir . '/logs/nope.log'));
    }

    public function testAnimeReportsOnlyRowsTheLogActuallyContains(): void
    {
        file_put_contents($this->dir . '/logs/strategy-benchmark-anime.log', implode("\n", [
            'wall: 4s',
            '{"strategy":"metadata_fuzzy","total":12,"duplicate_keys":3}',
        ]));

        $payload = $this->benchmark()->anime();

        $this->assertSame([[
            'strategy' => 'metadata_fuzzy',
            'wall_s' => 4,
            'merges' => 12,
            'dups' => 3,
        ]], $payload['recorded']);
        $this->assertSame('storage/logs/strategy-benchmark-anime.log', $payload['source']);
        $this->assertNotNull($payload['recorded_at']);
        $this->assertNull($payload['ml']);
    }

    public function testAnimeIsEmptyWithoutALog(): void
    {
        $payload = $this->benchmark()->anime();

        $this->assertSame([], $payload['recorded']);
        $this->assertNull($payload['source']);
        $this->assertNull($payload['recorded_at']);
    }

    public function testMangaReportsSpeedFromTheWallClock(): void
    {
        file_put_contents($this->dir . '/logs/strategy-benchmark-manga.log', implode("\n", [
            'wall: 10s',
            '{"strategy":"metadata_fuzzy","total":50,"duplicate_keys":1}',
        ]));

        $payload = $this->benchmark()->manga();

        $this->assertSame('strategy-benchmark-manga.log', $payload['source']);
        $this->assertSame([[
            'strategy' => 'metadata_fuzzy',
            'wall_s' => 10,
            'merges' => 50,
            'organized' => 50,
            'dups' => 1,
            'speed' => 5.0,
        ]], $payload['benchmark']);
    }

    public function testMlReportIsNullWithoutAReport(): void
    {
        $this->assertNull($this->benchmark()->mlReport());
    }

    public function testMlReportPicksTheBestRowWithTheSharedEvaluator(): void
    {
        $sweep = [
            ['threshold' => 60.0, 'predicted' => 9, 'tp' => 5, 'fp' => 4, 'tn' => 0, 'fn' => 0, 'precision' => 0.5, 'recall' => 1.0, 'f1' => 0.67, 'accuracy' => 0.5],
            ['threshold' => 80.0, 'predicted' => 4, 'tp' => 4, 'fp' => 0, 'tn' => 5, 'fn' => 0, 'precision' => 1.0, 'recall' => 1.0, 'f1' => 1.0, 'accuracy' => 1.0],
        ];
        $gate = BenchmarkEvaluator::gate(0.67, 1.0, 0.0);
        file_put_contents($this->dir . '/state/combine-benchmark.json', (string) json_encode([
            'created_at' => '2026-01-01T00:00:00+00:00',
            'input' => '/tmp/pairs.jsonl',
            'rows' => 9,
            'ml_model_version' => 'v1',
            'gate' => $gate,
            'scorers' => [
                'metadata_fuzzy' => ['name' => 'metadata_fuzzy', 'sweep' => $sweep, 'best' => ['stale' => true]],
                'metadata_ml' => ['name' => 'metadata_ml', 'sweep' => $sweep, 'best' => ['stale' => true]],
            ],
        ], JSON_PRESERVE_ZERO_FRACTION));

        $report = $this->benchmark()->mlReport();

        $this->assertNotNull($report);
        $this->assertSame(9, $report['rows']);
        $this->assertSame('v1', $report['ml_model_version']);
        $this->assertSame($gate, $report['gate']);
        $this->assertSame(['metadata_fuzzy', 'metadata_ml'], array_keys($report['best']));
        // The best row is the evaluator's, not the copy stored in the report.
        $this->assertSame(BenchmarkEvaluator::best($sweep), $report['best']['metadata_fuzzy']);
        $this->assertSame(80.0, $report['best']['metadata_fuzzy']['threshold']);
    }

    public function testMlReportKeepsTheStoredBestRowWhenThereIsNoSweep(): void
    {
        file_put_contents($this->dir . '/state/combine-benchmark.json', (string) json_encode([
            'scorers' => [
                'metadata_fuzzy' => ['best' => ['threshold' => 70.0, 'f1' => 0.8]],
            ],
        ], JSON_PRESERVE_ZERO_FRACTION));

        $report = $this->benchmark()->mlReport();

        $this->assertNotNull($report);
        $this->assertSame(['threshold' => 70.0, 'f1' => 0.8], $report['best']['metadata_fuzzy']);
        $this->assertNull($report['gate']);
    }
}
