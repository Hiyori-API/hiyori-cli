<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Commands\PipelineCommand;
use Hiyori\Hiyori;
use Hiyori\Service\Pipeline\PipelineChecks;
use Hiyori\Service\Pipeline\PreconditionResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class PipelineCommandTest extends TestCase
{
    private string $stateDir;

    /** @var list<string> */
    private array $paths = [];

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $calls;

    private Application $application;

    protected function setUp(): void
    {
        $this->calls = new \ArrayObject();
        $this->application = new Application();
        $this->addStub('migrate');
        $this->addStub('combine');
        $this->addStub('hiyori-id', 0, '{"backfill":{"anime":{"allocated":3}}}');
        $this->addStub('combine:dupes', 0, '{"ok":true,"empty_collections":[]}');
        $this->addStub('combine:dedupe', 0, '{"ok":true,"resolved":0}');
        $this->addStub('release');

        $this->stateDir = sys_get_temp_dir() . '/hiyori-pipeline-' . bin2hex(random_bytes(6));
        $this->paths[] = $this->stateDir;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($path);
            }
        }
        $this->paths = [];
    }

    private function addStub(string $name, int $exitCode = 0, string $output = ''): void
    {
        $this->application->add(new class ($name, $exitCode, $output, $this->calls) extends Command {
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
        });
    }

    private function checks(bool $pending = false, int $organized = 100, int $manga = 50): PipelineChecks
    {
        return new class ($pending, $organized, $manga) implements PipelineChecks {
            public function __construct(
                private bool $pending,
                private int $organized,
                private int $manga,
            ) {
            }

            public function database(): string
            {
                return 'hiyori_test';
            }

            public function databaseReachable(): PreconditionResult
            {
                return PreconditionResult::pass('database_reachable', 'stub');
            }

            public function noPendingMigrations(): PreconditionResult
            {
                return $this->pending
                    ? PreconditionResult::fail('no_pending_migrations', 'pending v9')
                    : PreconditionResult::pass('no_pending_migrations', 'none');
            }

            public function collectionHasDocuments(string $collection): PreconditionResult
            {
                $count = match ($collection) {
                    'organized' => $this->organized,
                    'organized_manga' => $this->manga,
                    default => 0,
                };

                return $count > 0
                    ? PreconditionResult::pass('collection_not_empty:' . $collection, 'stub ' . $count)
                    : PreconditionResult::fail('collection_not_empty:' . $collection, $collection . ' is empty');
            }
        };
    }

    /** @param array<string, mixed> $input */
    private function execute(array $input, ?PipelineChecks $checks = null): CommandTester
    {
        $command = new PipelineCommand(
            $this->createMock(Hiyori::class),
            $this->application,
            $checks ?? $this->checks(),
            $this->stateDir,
        );
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /** @return array<string, mixed> */
    private function record(CommandTester $tester): array
    {
        // The run record is the last JSON object on stdout. Human narration can
        // precede it when the tester shares one stream for stdout and stderr.
        $display = $tester->getDisplay();
        $start = strpos($display, '{');
        $decoded = $start === false ? null : json_decode(substr($display, $start), true);
        $this->assertIsArray($decoded, 'stdout was not a JSON run record: ' . $display);

        return $decoded;
    }

    /** @return list<string> */
    private function recordFiles(): array
    {
        return array_values(array_filter(
            glob($this->stateDir . '/pipeline-release-*.json') ?: [],
            static fn (string $file): bool => !str_ends_with($file, 'latest.json'),
        ));
    }

    public function testDryRunPrintsThePlanAndWritesNothing(): void
    {
        $tester = $this->execute(['--dry-run' => true, '--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], (array) $this->calls);
        $this->assertDirectoryDoesNotExist($this->stateDir);

        $record = $this->record($tester);
        $this->assertSame('dry-run', $record['status']);
        $this->assertNull($record['record_path']);
        $this->assertSame(
            ['migrate', 'combine:anime', 'combine:manga', 'hiyori-id', 'dupes', 'dedupe', 'release'],
            array_column($record['plan'], 'stage'),
        );
    }

    public function testRunWritesOneRecordToTheStateDirectoryAndStdout(): void
    {
        $tester = $this->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $stdout = $this->record($tester);

        $files = $this->recordFiles();
        $this->assertCount(1, $files);
        $this->assertFileExists($this->stateDir . '/pipeline-release-latest.json');

        $written = json_decode((string) file_get_contents($files[0]), true);
        $this->assertIsArray($written);
        $this->assertSame($stdout, $written);
        $this->assertSame('success', $written['status']);
        $this->assertSame($files[0], $written['record_path']);
        $this->assertCount(7, $written['stages']);
        $this->assertSame(['migrate', 'combine', 'combine', 'hiyori-id', 'combine:dupes', 'combine:dedupe', 'combine:dupes', 'release'], (array) $this->calls);
    }

    public function testPendingMigrationsStopTheChainBeforeCombining(): void
    {
        $tester = $this->execute(['--json' => true], $this->checks(pending: true));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $record = $this->record($tester);
        $this->assertSame('failed', $record['status']);
        $this->assertSame('combine:anime', $record['failed_stage']);
        $this->assertSame('no_pending_migrations', $record['stages'][1]['precondition']['name']);
        $this->assertSame(['migrate'], (array) $this->calls);
    }

    public function testCombineCoverageFailureStopsTheChain(): void
    {
        $this->addStub('combine', Command::FAILURE, "Coverage gate FAILED: 12% is below the 40% floor\n");

        $tester = $this->execute(['--json' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $record = $this->record($tester);
        $this->assertSame('combine:anime', $record['failed_stage']);
        $this->assertSame(['migrate', 'combine'], (array) $this->calls);
    }

    public function testDupesGateFailureStopsBeforeRelease(): void
    {
        $this->addStub('combine:dupes', Command::FAILURE, '{"ok":false,"empty_collections":[],"collections":{"organized":{"primary_duplicates":2}}}');

        $tester = $this->execute(['--json' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $record = $this->record($tester);
        $this->assertSame('release', $record['failed_stage']);
        $this->assertSame('dupes_gate', $record['stages'][6]['precondition']['name']);
        $this->assertTrue($record['stages'][4]['tolerated']);
        $this->assertContains('combine:dedupe', (array) $this->calls);
        $this->assertNotContains('release', (array) $this->calls);
    }

    public function testEmptyCollectionStopsTheGate(): void
    {
        $tester = $this->execute(['--json' => true, '--skip' => ['hiyori-id']], $this->checks(manga: 0));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $record = $this->record($tester);
        $this->assertSame('dupes', $record['failed_stage']);
        $this->assertSame('collection_not_empty:organized_manga', $record['stages'][3]['precondition']['name']);
        $this->assertNotContains('combine:dedupe', (array) $this->calls);
        $this->assertNotContains('release', (array) $this->calls);
    }

    public function testCombineLockHeldIsTreatedAsAFailure(): void
    {
        $this->addStub('combine', 0, 'The command is already running in another process.');

        $tester = $this->execute(['--json' => true]);

        $record = $this->record($tester);
        $this->assertSame('failed', $record['status']);
        $this->assertSame('combine:anime', $record['failed_stage']);
        $this->assertSame(Command::FAILURE, $record['stages'][1]['exit_code']);
    }

    public function testFromSkipsEarlierStages(): void
    {
        $tester = $this->execute(['--json' => true, '--from' => 'dupes']);

        $record = $this->record($tester);
        $this->assertSame(['dupes', 'dedupe', 'release'], array_column($record['plan'], 'stage'));
        $this->assertNotContains('migrate', (array) $this->calls);
        $this->assertNotContains('combine', (array) $this->calls);
    }

    public function testSkipRemovesStages(): void
    {
        $tester = $this->execute(['--json' => true, '--skip' => ['combine:manga', 'dedupe']]);

        $record = $this->record($tester);
        $this->assertSame(['migrate', 'combine:anime', 'hiyori-id', 'dupes', 'release'], array_column($record['plan'], 'stage'));
    }

    public function testUnknownStageIsRejectedWithoutRunningAnything(): void
    {
        $tester = $this->execute(['--skip' => ['nope']]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame([], (array) $this->calls);
        $this->assertStringContainsString('unknown stage', $tester->getDisplay());
    }

    public function testFromAndSkipContradictionIsRejected(): void
    {
        $tester = $this->execute(['--from' => 'dupes', '--skip' => ['dupes']]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame([], (array) $this->calls);
        $this->assertStringContainsString('contradict', $tester->getDisplay());
    }

    public function testCoverageFloorIsPassedThrough(): void
    {
        $tester = $this->execute(['--dry-run' => true, '--json' => true, '--min-coverage' => '55']);

        $record = $this->record($tester);
        $this->assertSame('55', $record['plan'][1]['params']['--min-coverage']);
        $this->assertSame('55', $record['plan'][2]['params']['--min-coverage']);
    }

    public function testReleaseOptionsArePassedThrough(): void
    {
        $tester = $this->execute([
            '--dry-run' => true,
            '--json' => true,
            '--tag' => 'data-2026.42',
            '--repo' => 'me/db',
            '--branch' => 'main',
            '--draft' => true,
        ]);

        $release = $this->record($tester)['plan'][6];
        $this->assertSame('release', $release['command']);
        $this->assertSame('data-2026.42', $release['params']['--tag']);
        $this->assertSame('me/db', $release['params']['--repo']);
        $this->assertSame('main', $release['params']['--branch']);
        $this->assertTrue($release['params']['--draft']);
        $this->assertTrue($release['params']['--verify']);
    }

    public function testAnUnwritableStateDirectoryIsLoud(): void
    {
        file_put_contents($this->stateDir, 'this is a file, not a directory');

        $tester = $this->execute(['--json' => true]);

        $record = $this->record($tester);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertArrayHasKey('record_error', $record);
    }
}
