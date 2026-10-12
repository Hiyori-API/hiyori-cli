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
        $router = dirname(__DIR__, 2) . '/public/index.php';
        if (!is_file($router)) {
            self::markTestSkipped('public/index.php missing');
        }
        $port = 18000 + random_int(0, 500);
        self::$base = "http://127.0.0.1:$port";
        self::$stateDir = dirname(__DIR__, 2) . '/storage/state';

        self::$proc = proc_open(
            PHP_BINARY . ' -S 127.0.0.1:' . $port . ' ' . $router,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            self::$pipes,
            dirname(__DIR__, 2),
        );
        // wait for readiness
        for ($i = 0; $i < 30; $i++) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            $s = @file_get_contents(self::$base . '/api/status', false, $ctx);
            if ($s !== false) {
                return;
            }
            usleep(200_000);
        }
        self::markTestSkipped('dashboard server did not start');
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
