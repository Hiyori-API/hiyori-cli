<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Telemetry;
use PHPUnit\Framework\TestCase;

final class TelemetryTest extends TestCase
{
    private string $tmp;

    private Telemetry $telemetry;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/hiyori-telemetry-' . uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->telemetry = new Telemetry($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testUpdateAndReadMergesState(): void
    {
        $this->telemetry->update('ingest-test', ['source' => 'test', 'processed' => 10]);
        $this->telemetry->update('ingest-test', ['processed' => 20, 'errors' => 1]);

        $state = $this->telemetry->read('ingest-test');
        $this->assertSame('test', $state['source']);
        $this->assertSame(20, $state['processed']);
        $this->assertSame(1, $state['errors']);
        $this->assertArrayHasKey('updated_at', $state);
        $this->assertArrayHasKey('ingest-test', $this->telemetry->allState());
    }

    public function testMissingStateReturnsEmpty(): void
    {
        $this->assertSame([], $this->telemetry->read('does-not-exist'));
    }

    public function testAppendAndTailJsonl(): void
    {
        $this->telemetry->append('validation-log', ['source' => 'anilist', 'errors' => ['title' => ['x']]]);
        $this->telemetry->append('validation-log', ['source' => 'kitsu', 'errors' => []]);

        $lines = $this->telemetry->tail('validation-log', 10);
        $this->assertCount(2, $lines);
        $this->assertSame('anilist', $lines[0]['source']);
        $this->assertArrayHasKey('ts', $lines[0]);
    }

    public function testPushHistoryKeepsRingBufferBounded(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->telemetry->pushHistory('ingest-h', (float) $i, 10);
        }
        $state = $this->telemetry->read('ingest-h');
        $this->assertCount(10, $state['history']);
        // newest point last
        $this->assertEquals(14.0, $state['history'][9]['v']);
    }

    public function testTrackComputesRateAndEta(): void
    {
        $this->telemetry->update('ingest-track', ['started_at_ts' => time() - 10]);
        Telemetry::track('track', 50, 200, 2, 'running', 'initial', [], $this->tmp);
        $state = $this->telemetry->read('ingest-track');
        $this->assertSame('running', $state['phase']);
        $this->assertSame(50, $state['processed']);
        // 50 / 10s = 5.0/s
        $this->assertEquals(5.0, $state['per_second']);
        // eta = (200-50)/5 = 30s
        $this->assertSame(30, $state['eta_seconds']);
        $this->assertArrayHasKey('history', $state);
    }
}
