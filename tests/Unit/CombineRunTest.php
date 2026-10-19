<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\CombineRun;
use PHPUnit\Framework\TestCase;

/**
 * "Is a combine running?".
 *
 * The dashboard's own run is tracked by a pid marker; a run the operator
 * started from a terminal is only visible as a process. The service owns
 * both, and a stale marker must not keep the Rebuild button disabled.
 */
final class CombineRunTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hiyori-combine-run-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function combineRun(): CombineRun
    {
        return new CombineRun($this->dir);
    }

    public function testNotRunningWithoutAMarker(): void
    {
        $this->assertFalse($this->combineRun()->running());
    }

    public function testMarkStartedRecordsThePidAndTheRun(): void
    {
        $run = $this->combineRun();
        $run->markStarted(4242, 'anilist', 'metadata_fuzzy');

        $marker = json_decode((string) file_get_contents($this->dir . '/combine-run.json'), true);
        $this->assertIsArray($marker);
        $this->assertSame(4242, $marker['pid']);
        $this->assertSame('anilist', $marker['base']);
        $this->assertSame('metadata_fuzzy', $marker['strategy']);
        $this->assertArrayHasKey('started_at', $marker);
    }

    public function testALiveProcessCountsAsRunning(): void
    {
        $run = $this->combineRun();
        $run->markStarted(getmypid(), 'anilist', 'metadata_fuzzy');

        $this->assertTrue($run->running());
    }

    public function testAStaleMarkerClearsItself(): void
    {
        // A pid that cannot be alive: the highest pid the kernel may hand out
        // is far below this one.
        file_put_contents($this->dir . '/combine-run.json', (string) json_encode(['pid' => 4194304]));

        $this->assertFalse($this->combineRun()->running());
        $this->assertFileDoesNotExist($this->dir . '/combine-run.json');
    }

    public function testAMarkerWithoutAPidIsNotRunning(): void
    {
        file_put_contents($this->dir . '/combine-run.json', (string) json_encode(['base' => 'anilist']));

        $this->assertFalse($this->combineRun()->running());
    }

    public function testProcessRunningAnswersWithABool(): void
    {
        $this->assertIsBool($this->combineRun()->processRunning());
    }
}
