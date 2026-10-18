<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Telemetry;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency and failure-visibility coverage for the state store.
 *
 * Every test uses its own temp directory; none of these touch storage/state.
 */
final class TelemetryConcurrencyTest extends TestCase
{
    private string $tmp;

    private Telemetry $telemetry;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/hiyori-telemetry-concurrency-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
        $this->telemetry = new Telemetry($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    public function testIncrementKeepsCounterAndMergesExtraFields(): void
    {
        $this->telemetry->increment('cache', 'requests', 1, ['enabled' => true, 'host' => 'a.example']);
        $this->telemetry->increment('cache', 'requests', 1, ['enabled' => false, 'host' => 'b.example']);

        $state = $this->telemetry->read('cache');
        $this->assertSame(2, $state['requests']);
        $this->assertFalse($state['enabled'], 'Extra fields use last-write-wins like update()');
        $this->assertSame('b.example', $state['host']);
        $this->assertArrayHasKey('updated_at', $state);
    }

    public function testParallelIncrementsFromSeveralProcessesAreNotLost(): void
    {
        // Mirrors scripts/ingest-parallel.sh: three workers, one shared counter.
        $workers = [];
        foreach ([1, 2, 3] as $worker) {
            $workers[] = $this->spawnWorker(
                <<<'PHP'
                    require $argv[1];
                    $telemetry = new \Hiyori\Service\Telemetry($argv[2]);
                    for ($i = 0; $i < (int) $argv[3]; $i++) {
                        $telemetry->increment('cache', 'requests');
                    }
                    PHP,
                [dirname(__DIR__, 2) . '/vendor/autoload.php', $this->tmp, '40'],
                'increment-' . $worker,
            );
        }
        foreach ($workers as $worker) {
            $this->waitWorker($worker);
        }

        $state = $this->telemetry->read('cache');
        $this->assertSame(120, $state['requests'], 'Every increment must survive concurrent workers');
    }

    public function testUpdateBlocksOnTheLockAndMergesStateWrittenByAnotherProcess(): void
    {
        // The child takes the lock, announces itself, then writes after the
        // parent could have read the old file. Without a lock the parent reads
        // an empty file and its write drops the child's field.
        $worker = $this->spawnWorker(
            <<<'PHP'
                $file = $argv[1] . '/cache.json';
                $lock = fopen($file . '.lock', 'c');
                if (false === $lock || !flock($lock, LOCK_EX)) {
                    fwrite(STDERR, "worker could not lock\n");
                    exit(2);
                }
                file_put_contents($argv[1] . '/ready', '1');
                usleep(300000);
                file_put_contents($file, json_encode(['requests' => 5, 'source' => 'worker']));
                usleep(700000);
                flock($lock, LOCK_UN);
                fclose($lock);
                PHP,
            [$this->tmp],
            'lock-holder',
        );

        $this->waitForFile($this->tmp . '/ready');
        $this->telemetry->increment('cache', 'requests');
        $this->waitWorker($worker);

        $state = $this->telemetry->read('cache');
        $this->assertSame('worker', $state['source'] ?? null, 'The other writer\'s field was lost');
        $this->assertSame(6, $state['requests'], 'Increment must be applied to the value written under the lock');
    }

    public function testParallelAppendsFromSeveralProcessesAreNotLost(): void
    {
        $workers = [];
        foreach ([1, 2, 3] as $worker) {
            $workers[] = $this->spawnWorker(
                <<<'PHP'
                    require $argv[1];
                    $telemetry = new \Hiyori\Service\Telemetry($argv[2]);
                    for ($i = 0; $i < (int) $argv[3]; $i++) {
                        $telemetry->append('events', ['worker' => $argv[4], 'i' => $i], 5000);
                    }
                    PHP,
                [dirname(__DIR__, 2) . '/vendor/autoload.php', $this->tmp, '30', (string) $worker],
                'append-' . $worker,
            );
        }
        foreach ($workers as $worker) {
            $this->waitWorker($worker);
        }

        $this->assertCount(90, $this->telemetry->tail('events', 1000));
    }

    public function testFailedStateWriteIsReportedInsteadOfSwallowed(): void
    {
        $handler = new TestHandler();
        $telemetry = new Telemetry($this->tmp, new Logger('telemetry-test', [$handler]));

        // file_put_contents() targets <file>.tmp; making that path a directory
        // fails deterministically, even when the test suite runs as root.
        mkdir($this->tmp . '/cache.json.tmp');

        $telemetry->update('cache', ['requests' => 1]);

        $this->assertTrue($handler->hasErrorRecords(), 'A failed write must leave a log record');
        $records = $handler->getRecords();
        $this->assertSame($this->tmp . '/cache.json', $records[0]['context']['path']);
        $this->assertArrayHasKey('error', $records[0]['context']);
        $this->assertSame([], $telemetry->read('cache'), 'The failed write must not publish partial state');
    }

    public function testFailedRenameIsReportedInsteadOfSwallowed(): void
    {
        $handler = new TestHandler();
        $telemetry = new Telemetry($this->tmp, new Logger('telemetry-test', [$handler]));

        // The temp file writes fine but rename() cannot publish it over a
        // directory, so the failure happens after the write.
        mkdir($this->tmp . '/cache.json');

        $telemetry->update('cache', ['requests' => 1]);

        $this->assertTrue($handler->hasErrorRecords(), 'A failed rename must leave a log record');
        $this->assertSame(
            $this->tmp . '/cache.json',
            $handler->getRecords()[0]['context']['path'],
        );
    }

    public function testUnavailableLockIsReportedInsteadOfWritingUnlocked(): void
    {
        $handler = new TestHandler();
        $telemetry = new Telemetry($this->tmp, new Logger('telemetry-test', [$handler]));

        // fopen('...', 'c') fails when the lock path is a directory.
        mkdir($this->tmp . '/cache.json.lock');

        $telemetry->update('cache', ['requests' => 1]);

        $this->assertTrue($handler->hasErrorRecords(), 'Failing to lock must be visible');
        $this->assertSame(
            $this->tmp . '/cache.json.lock',
            $handler->getRecords()[0]['context']['path'],
        );
        $this->assertFileDoesNotExist($this->tmp . '/cache.json.tmp');
    }

    /**
     * @param list<string> $args
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function spawnWorker(string $source, array $args, string $label): array
    {
        $script = $this->tmp . '/worker-' . $label . '-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n" . $source);

        $process = proc_open(
            array_merge([PHP_BINARY, $script], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            $this->fail('Could not start worker process');
        }

        return [$process, $pipes, $script];
    }

    /**
     * @param array{0: resource, 1: array<int, resource>, 2: string} $worker
     */
    private function waitWorker(array $worker): void
    {
        [$process, $pipes, $script] = $worker;
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        @unlink($script);

        $this->assertSame(0, $exit, 'Worker failed: ' . $stderr . $stdout);
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }

        $this->assertFileExists($path, 'Worker never signalled readiness');
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $this->removeTree($path . '/' . $entry);
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
