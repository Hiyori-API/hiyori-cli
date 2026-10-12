<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class DashboardApiTest extends TestCase
{
    private static $proc;

    private static array $pipes = [];

    private static string $base = '';

    private static string $stateDir = '';

    public static function setUpBeforeClass(): void
    {
        // Opt-in: point HIHYORI_API_BASE at a running dashboard server
        // (e.g. php hiyori dashboard:serve --port 8080). Without it the class
        // is skipped so CI never boots a server or probes external APIs.
        self::$base = (string) (getenv('HIHYORI_API_BASE') ?: '');
        if (self::$base === '') {
            self::markTestSkipped('set HIHYORI_API_BASE to a running dashboard to run API smoke tests');
        }
        self::$stateDir = dirname(__DIR__, 2) . '/storage/state';
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        @unlink(self::$stateDir . '/control-dash-test.json');
    }

    private function getJson(string $path): array
    {
        $s = @file_get_contents(self::$base . $path, false, stream_context_create(['http' => ['timeout' => 8]]));
        $d = json_decode((string) $s, true);
        self::assertIsArray($d, "GET $path should return JSON");

        return $d;
    }

    public function testStatusReturnsCountsAndHealth(): void
    {
        $d = $this->getJson('/api/status');
        $this->assertArrayHasKey('counts', $d);
        $this->assertArrayHasKey('health', $d);
        $this->assertArrayHasKey('jobs', $d);
        $this->assertArrayHasKey('db', $d);
        $this->assertArrayHasKey('backup', $d);
        $this->assertArrayHasKey('cache', $d);
        $this->assertArrayHasKey('validation', $d);
        $this->assertIsInt($d['db']['totalDocuments'] ?? -1);
    }

    public function testDbStats(): void
    {
        $d = $this->getJson('/api/db');
        $this->assertArrayHasKey('dataSize', $d['db']);
        $this->assertArrayHasKey('indexSize', $d['db']);
        $this->assertArrayHasKey('collections', $d['db']);
    }

    public function testActionPauseResumeRoundTrip(): void
    {
        $d = $this->getJson('/api/action?job=dash-test&cmd=pause');
        $this->assertSame('dash-test', $d['job']);
        $this->assertSame('pause', $d['cmd']);
        $this->assertTrue($d['control']['paused']);

        $d = $this->getJson('/api/action?job=dash-test&cmd=resume');
        $this->assertFalse($d['control']['paused']);
    }

    public function testBackupInfoAndRun(): void
    {
        $d = $this->getJson('/api/backup');
        $this->assertArrayHasKey('backup', $d);
        $this->assertArrayHasKey('exists', $d['backup']);

        $d = $this->getJson('/api/backup?run=1');
        $this->assertTrue($d['started'] ?? false);
    }

    public function testMetrics(): void
    {
        $body = @file_get_contents(self::$base . '/metrics', false, stream_context_create(['http' => ['timeout' => 8]]));
        $this->assertStringContainsString('# HELP', (string) $body);
    }
}
