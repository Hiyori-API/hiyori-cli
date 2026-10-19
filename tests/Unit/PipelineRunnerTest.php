<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Pipeline\PipelineRunner;
use Hiyori\Service\Pipeline\Precondition;
use Hiyori\Service\Pipeline\PreconditionResult;
use Hiyori\Service\Pipeline\Stage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class PipelineRunnerTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        $this->calls = new \ArrayObject();
    }

    private function runner(Application $application): PipelineRunner
    {
        return new PipelineRunner($application, new BufferedOutput(), false);
    }

    private function stub(string $name, int $exitCode = 0, string $output = ''): Command
    {
        return new class ($name, $exitCode, $output, $this->calls) extends Command {
            public function __construct(
                string $name,
                private int $exitCode,
                private string $stdout,
                private \ArrayObject $calls,
            ) {
                parent::__construct($name);
                $this->ignoreValidationErrors();
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->calls[] = (string) $this->getName();
                if ($this->stdout !== '') {
                    $output->write($this->stdout);
                }

                return $this->exitCode;
            }
        };
    }

    private function application(): Application
    {
        $application = new Application();
        $application->add($this->stub('migrate', 0, 'migrated'));
        $application->add($this->stub('combine', 0, "Coverage: 9 of 10 base rows covered (90.0%) — merged rows: 9, single-source rows: 0, unmatched rows: 1, skipped rows (no base reference id): 0\n"));
        $application->add($this->stub('hiyori-id', 0, '{"backfill":{"anime":{"allocated":3}}}'));
        $application->add($this->stub('combine:dupes', 0, '{"ok":true,"empty_collections":[],"collections":[]}'));
        $application->add($this->stub('combine:dedupe', 0, '{"ok":true,"resolved":0}'));
        $application->add($this->stub('release', 0, 'published'));

        return $application;
    }

    public function testRunsStagesInOrderAndRecordsWhatEachReported(): void
    {
        $record = $this->runner($this->application())->run([
            new Stage('migrate', 'Migrate', 'migrate', []),
            new Stage('combine:anime', 'Combine', 'combine', ['base' => 'anilist']),
            new Stage('hiyori-id', 'Ids', 'hiyori-id', ['--backfill' => true, '--json' => true]),
        ], 'run-1', 'hiyori_test', false);

        $this->assertSame('success', $record['status']);
        $this->assertSame(0, $record['exit_code']);
        $this->assertFalse($record['dry_run']);
        $this->assertSame(['migrate', 'combine:anime', 'hiyori-id'], array_column($record['stages'], 'stage'));
        $this->assertSame(['migrate', 'combine', 'hiyori-id'], (array) $this->calls);

        $this->assertSame(['output' => 'migrated'], $record['stages'][0]['reported']);
        $this->assertSame(90.0, $record['stages'][1]['reported']['coverage']['coverage_percent']);
        $this->assertSame(3, $record['stages'][2]['reported']['backfill']['anime']['allocated']);
        $this->assertIsInt($record['stages'][0]['duration_ms']);
    }

    public function testStopsWhenAPreconditionFailsAndDoesNotRunTheStage(): void
    {
        $stages = [
            new Stage('migrate', 'Migrate', 'migrate', []),
            new Stage('combine:anime', 'Combine', 'combine', ['base' => 'anilist'], new Precondition(
                'no_pending_migrations',
                'combine refuses to run while migrations are pending',
                static fn (): PreconditionResult => PreconditionResult::fail('no_pending_migrations', 'pending v9'),
            )),
            new Stage('release', 'Release', 'release', []),
        ];

        $record = $this->runner($this->application())->run($stages, 'run-2', 'hiyori_test', false);

        $this->assertSame('failed', $record['status']);
        $this->assertSame(Command::FAILURE, $record['exit_code']);
        $this->assertSame('combine:anime', $record['failed_stage']);
        $this->assertSame(['migrate'], (array) $this->calls);
        $this->assertSame('aborted', $record['stages'][1]['status']);
        $this->assertSame('pending v9', $record['stages'][1]['failure_reason']);
        $this->assertCount(2, $record['stages']);
    }

    public function testPropagatesANonZeroExitCodeAndStopsTheChain(): void
    {
        $application = new Application();
        $application->add($this->stub('combine', Command::FAILURE, "Coverage gate FAILED\n"));
        $application->add($this->stub('release', 0, 'published'));

        $record = $this->runner($application)->run([
            new Stage('combine:anime', 'Combine', 'combine', ['base' => 'anilist']),
            new Stage('release', 'Release', 'release', []),
        ], 'run-3', 'hiyori_test', false);

        $this->assertSame('failed', $record['status']);
        $this->assertSame('combine:anime', $record['failed_stage']);
        $this->assertSame(Command::FAILURE, $record['stages'][0]['exit_code']);
        $this->assertSame(['combine'], (array) $this->calls);
    }

    public function testToleratesARepairableFailureWhenTheRepairStageFollows(): void
    {
        $application = new Application();
        $application->add($this->stub('combine:dupes', Command::FAILURE, '{"ok":false}'));
        $application->add($this->stub('combine:dedupe', 0, '{"ok":true,"resolved":2}'));
        $application->add($this->stub('release', 0, 'published'));

        $record = $this->runner($application)->run([
            new Stage('dupes', 'Dupes', 'combine:dupes', ['--json' => true], null, repairableBy: 'dedupe'),
            new Stage('dedupe', 'Dedupe', 'combine:dedupe', ['--json' => true]),
            new Stage('release', 'Release', 'release', []),
        ], 'run-4', 'hiyori_test', false);

        $this->assertSame('success', $record['status']);
        $this->assertSame(['dupes'], $record['tolerated_failures']);
        $this->assertTrue($record['stages'][0]['tolerated']);
        $this->assertSame('dedupe', $record['stages'][0]['repaired_by']);
        $this->assertSame(['combine:dupes', 'combine:dedupe', 'release'], (array) $this->calls);
    }

    public function testARepairableFailureStillStopsWhenTheRepairIsNotScheduled(): void
    {
        $application = new Application();
        $application->add($this->stub('combine:dupes', Command::FAILURE, '{"ok":false}'));
        $application->add($this->stub('release', 0, 'published'));

        $record = $this->runner($application)->run([
            new Stage('dupes', 'Dupes', 'combine:dupes', ['--json' => true], null, repairableBy: 'dedupe'),
            new Stage('release', 'Release', 'release', []),
        ], 'run-5', 'hiyori_test', false);

        $this->assertSame('failed', $record['status']);
        $this->assertSame('dupes', $record['failed_stage']);
        $this->assertSame(['combine:dupes'], (array) $this->calls);
    }

    public function testDryRunEvaluatesReadOnlyPreconditionsButRunsNothing(): void
    {
        $stages = [
            new Stage('migrate', 'Migrate', 'migrate', []),
            new Stage('release', 'Release', 'release', [], new Precondition(
                'dupes_gate',
                'the live gate runs a command',
                static fn (): PreconditionResult => PreconditionResult::fail('dupes_gate', 'should not be evaluated'),
                isCommand: true,
            )),
        ];

        $record = $this->runner($this->application())->run($stages, 'run-6', 'hiyori_test', true);

        $this->assertSame('dry-run', $record['status']);
        $this->assertTrue($record['dry_run']);
        $this->assertSame(0, $record['exit_code']);
        $this->assertSame([], (array) $this->calls);
        $this->assertSame('planned', $record['stages'][0]['status']);
        $this->assertNull($record['stages'][0]['exit_code']);
        $this->assertTrue($record['stages'][1]['precondition']['deferred']);
        $this->assertNull($record['failed_stage']);
        $this->assertNull($record['would_fail_at']);
    }

    public function testDryRunReportsWhereAReadOnlyPreconditionWouldFail(): void
    {
        $stages = [
            new Stage('migrate', 'Migrate', 'migrate', [], new Precondition(
                'database_reachable',
                'needs MongoDB',
                static fn (): PreconditionResult => PreconditionResult::fail('database_reachable', 'down'),
            )),
            new Stage('release', 'Release', 'release', []),
        ];

        $record = $this->runner($this->application())->run($stages, 'run-7', 'hiyori_test', true);

        $this->assertSame('dry-run', $record['status']);
        $this->assertSame(Command::FAILURE, $record['exit_code']);
        $this->assertSame('migrate', $record['would_fail_at']);
        $this->assertSame([], (array) $this->calls);
    }

    public function testAFailureSignatureTurnsAZeroExitIntoAFailure(): void
    {
        $application = new Application();
        $application->add($this->stub('combine', 0, 'The command is already running in another process.'));

        $record = $this->runner($application)->run([
            new Stage(
                'combine:anime',
                'Combine',
                'combine',
                ['base' => 'anilist'],
                null,
                failureSignature: static fn (string $output, int $exit): ?string => str_contains($output, 'already running in another process')
                    ? 'the lock is held'
                    : null,
            ),
        ], 'run-8', 'hiyori_test', false);

        $this->assertSame('failed', $record['status']);
        $this->assertSame(Command::FAILURE, $record['stages'][0]['exit_code']);
        $this->assertSame('the lock is held', $record['stages'][0]['failure_reason']);
    }

    public function testMissingCommandIsAFailureNotAnException(): void
    {
        $record = $this->runner(new Application())->run([
            new Stage('release', 'Release', 'release', []),
        ], 'run-9', 'hiyori_test', false);

        $this->assertSame('failed', $record['status']);
        $this->assertSame(Command::FAILURE, $record['stages'][0]['exit_code']);
    }
}
