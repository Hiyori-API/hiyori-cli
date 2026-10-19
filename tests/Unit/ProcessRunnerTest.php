<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * The command runner behind the backup and restore buttons.
 *
 * The important property is the argv array: a command string is a shell
 * program, and one caller that quotes it wrong turns a dashboard button into
 * remote code execution.
 */
final class ProcessRunnerTest extends TestCase
{
    public function testRunReportsTheExitCode(): void
    {
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'exit(0);']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('', $result['output']);
    }

    public function testRunReportsAFailure(): void
    {
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'fwrite(STDERR, "boom\n"); exit(3);']);

        $this->assertSame(3, $result['exit_code']);
        $this->assertStringContainsString('boom', $result['output']);
    }

    public function testRunCapturesStdout(): void
    {
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'echo "hello";']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('hello', $result['output']);
    }

    public function testArgumentsAreNotShellInterpreted(): void
    {
        $marker = sys_get_temp_dir() . '/hiyori-rce-' . bin2hex(random_bytes(6));
        $payload = 'x; touch ' . $marker;

        $result = ProcessRunner::run([PHP_BINARY, '-r', 'echo $argv[1];', '--', $payload]);

        $this->assertSame($payload, $result['output']);
        $this->assertFileDoesNotExist($marker, 'the argument must never reach a shell');
    }

    public function testAnEmptyCommandIsRejected(): void
    {
        $result = ProcessRunner::run([]);

        $this->assertSame(127, $result['exit_code']);
        $this->assertNotSame('', $result['output']);
    }

    public function testTheOutputIsBoundedToTheLastLines(): void
    {
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'for ($i = 1; $i <= 40; ++$i) { echo "line $i\n"; }']);

        $this->assertSame(0, $result['exit_code']);
        $lines = explode("\n", $result['output']);
        $this->assertCount(ProcessRunner::TAIL_LINES, $lines);
        $this->assertSame('line 40', $lines[count($lines) - 1]);
        $this->assertSame('line 31', $lines[0]);
    }

    public function testALongLineIsTruncated(): void
    {
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'echo str_repeat("x", 5000);']);

        $this->assertSame(ProcessRunner::MAX_LINE_LENGTH, strlen($result['output']));
    }

    public function testTheWorkingDirectoryIsUsed(): void
    {
        $dir = sys_get_temp_dir() . '/hiyori-runner-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);

        try {
            $result = ProcessRunner::run([PHP_BINARY, '-r', 'echo getcwd();'], $dir);

            $this->assertSame(0, $result['exit_code']);
            $this->assertSame(realpath($dir), $result['output']);
        } finally {
            @rmdir($dir);
        }
    }
}
