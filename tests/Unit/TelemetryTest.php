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

    // ---------------------------------------------------------------------
    // The audit log, the JSONL tails and the control files — the three
    // things the dashboard reads that used to be free functions in the
    // front controller.
    // ---------------------------------------------------------------------

    public function testAuditAppendsOneLinePerCall(): void
    {
        $this->telemetry->audit('settings.update', ['keys' => ['theme']]);
        $this->telemetry->audit('env.update', ['key' => 'WEBHOOK_URL']);

        $lines = file($this->tmp . '/audit.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $this->assertIsArray($first);
        $this->assertSame('settings.update', $first['action']);
        $this->assertSame(['keys' => ['theme']], $first['detail']);
        $this->assertArrayHasKey('ts', $first);
        $this->assertArrayHasKey('ip', $first);
    }

    public function testAuditRecordsTheGivenIp(): void
    {
        $this->telemetry->audit('restore', ['ok' => true], '203.0.113.7');

        $line = json_decode((string) file_get_contents($this->tmp . '/audit.jsonl'), true);
        $this->assertIsArray($line);
        $this->assertSame('203.0.113.7', $line['ip']);
    }

    public function testAuditCreatesTheStateDirectory(): void
    {
        $nested = $this->tmp . '/nested';
        (new Telemetry($nested))->audit('settings.update');

        $this->assertDirectoryExists($nested);
    }

    public function testTailLinesMetaCountsEveryLineButReturnsOnlyTheTail(): void
    {
        file_put_contents($this->tmp . '/validation-log.jsonl', implode("\n", [
            (string) json_encode(['ts' => '1', 'source' => 'a']),
            (string) json_encode(['ts' => '2', 'source' => 'b']),
            (string) json_encode(['ts' => '3', 'source' => 'c']),
        ]));

        $meta = $this->telemetry->tailLinesMeta('validation-log', 2);

        $this->assertSame(3, $meta['total']);
        $this->assertCount(2, $meta['events']);
        $this->assertSame('b', $meta['events'][0]['source']);
        $this->assertSame('c', $meta['events'][1]['source']);
    }

    public function testTailLinesMetaIsEmptyForAMissingLog(): void
    {
        $this->assertSame(['total' => 0, 'events' => []], $this->telemetry->tailLinesMeta('nope', 10));
    }

    public function testControlReadsTheControlFile(): void
    {
        file_put_contents($this->tmp . '/control-anilist.json', (string) json_encode(['paused' => true]));

        $this->assertSame(['paused' => true], $this->telemetry->control('anilist'));
    }

    public function testControlIsNotPausedWithoutAControlFile(): void
    {
        $this->assertSame(['paused' => false], $this->telemetry->control('anilist'));
    }
}
