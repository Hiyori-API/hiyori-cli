<?php

declare(strict_types=1);

namespace Hiyori\Service;

use Symfony\Component\Process\Process;

/**
 * Runs a command to completion and reports its real outcome.
 *
 * The backup/restore routes fired `... >/dev/null 2>&1 &` through `@exec`, so
 * the child's exit status was thrown away: a failed mongodump still answered
 * `started: true`, and a failed `mongorestore --drop` left the database
 * dropped and empty while the UI toasted success. Running to completion is
 * what makes the exit code real.
 *
 * The API takes an argv array, never a command string. A string is a shell
 * program: quoting it correctly is the caller's job, and one caller that gets
 * it wrong turns a backup button into remote code execution. With an argv
 * array there is no shell in the middle, and the arguments cannot be
 * reinterpreted as operators.
 */
final class ProcessRunner
{
    /** The longest output line kept in the result. */
    public const MAX_LINE_LENGTH = 500;

    /** How many trailing output lines are kept. */
    public const TAIL_LINES = 10;

    /**
     * Default timeout in seconds.
     *
     * A dump of this dataset takes seconds, a restore takes tens of seconds;
     * an hour is generous, and it is still a limit where the old `@exec` had
     * none.
     */
    public const DEFAULT_TIMEOUT = 3600.0;

    private function __construct()
    {
    }

    /**
     * Run a command and wait for it to finish.
     *
     * @param list<string> $argv             the program and its arguments, one
     *                                        element each — no shell, no
     *                                        quoting
     * @param string|null  $workingDirectory where to run it
     * @param float|null   $timeout          seconds before the child is killed
     *
     * @return array{exit_code: int, output: string} output is the tail of
     *                                               stdout+stderr (bounded so a
     *                                               chatty run cannot bloat the
     *                                               JSON response or the audit log)
     */
    public static function run(array $argv, ?string $workingDirectory = null, ?float $timeout = null): array
    {
        if ($argv === []) {
            return ['exit_code' => 127, 'output' => 'no command given'];
        }
        if (!function_exists('proc_open')) {
            return ['exit_code' => 127, 'output' => 'proc_open() is disabled'];
        }

        $process = new Process(array_values($argv), $workingDirectory);
        $process->setTimeout($timeout ?? self::DEFAULT_TIMEOUT);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [
                'exit_code' => 124,
                'output' => substr($e->getMessage(), 0, self::MAX_LINE_LENGTH),
            ];
        }

        return [
            'exit_code' => (int) ($process->getExitCode() ?? 127),
            'output' => self::tail($process->getOutput() . $process->getErrorOutput()),
        ];
    }

    /**
     * The last few lines of a run's output, each one bounded.
     */
    private static function tail(string $output): string
    {
        $output = rtrim($output, "\r\n");
        if ($output === '') {
            return '';
        }

        $lines = array_slice(explode("\n", $output), -self::TAIL_LINES);

        return implode("\n", array_map(
            static fn (string $line): string => substr($line, 0, self::MAX_LINE_LENGTH),
            $lines,
        ));
    }
}
