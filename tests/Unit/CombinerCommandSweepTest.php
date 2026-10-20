<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Commands\CombinerCommand;
use Hiyori\Hiyori;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Combiner\CombineStats;
use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Collection;
use MongoDB\DeleteResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `combine` must reconcile the target it wrote: after a successful run the
 * documents the run did not produce are deleted, scoped to the base's own
 * reference key.
 *
 * The combiner is mocked (it returns the stats the real strategies honour)
 * and the target collection is a mock too, so the test asserts the delete
 * filter the command builds rather than what a server does with it.
 */
final class CombinerCommandSweepTest extends TestCase
{
    private const TARGET = 'sweep_command_test';

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/hiyori-combine-sweep-test-' . getmypid();
        putenv('HIYORI_TELEMETRY_DIR=' . self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        putenv('HIYORI_TELEMETRY_DIR');
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        // Never contend with a real combine: the production lock is held for
        // the whole duration of a rebuild.
        $_ENV['COMBINE_LOCK_NAME'] = 'combine-sweep-test-' . getmypid();
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['COMBINE_LOCK_NAME']);
    }

    /**
     * A Migrator with every known migration stubbed as applied, so `pending()`
     * returns [] without touching MongoDB.
     */
    private function migrator(): Migrator
    {
        $_ENV['MONGODB_URL'] ??= 'mongodb://localhost:27017';

        $db = new Database(SerializerBuilder::create()->build());
        // Every known migration counts as applied. Hard-coding the list
        // made each new migration look pending, and the command refused to
        // run until the test was edited.
        $stub = array_fill_keys(array_keys((new Migrator($db, 'hiyori_test_sweep_cmd', []))->versions()), 'applied');

        return new Migrator($db, 'hiyori_test_sweep_cmd', $stub);
    }

    /**
     * @param array<string, mixed> $arguments extra CLI arguments
     * @param list<array<string, mixed>> $filters receives the delete filters
     */
    private function runCommand(array $arguments, CombineStats $stats, array &$filters = []): CommandTester
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('deleteMany')->willReturnCallback(
            function (array $filter) use (&$filters): DeleteResult {
                $filters[] = $filter;
                $result = $this->createMock(DeleteResult::class);
                $result->method('getDeletedCount')->willReturn(3);

                return $result;
            },
        );

        $database = $this->createMock(Database::class);
        $database->method('getCollection')->willReturn($collection);

        $hiyori = $this->createMock(Hiyori::class);
        $hiyori->method('getMigrator')->willReturn($this->migrator());
        $hiyori->method('getDatabase')->willReturn($database);
        $hiyori->method('combine')->willReturn($stats);

        $tester = new CommandTester(new CombinerCommand($hiyori));
        $tester->execute(array_merge(['base' => 'anilist', '--into' => self::TARGET], $arguments));

        return $tester;
    }

    public function testTheSweepRunsAfterASuccessfulRun(): void
    {
        // The run left a checkpoint, so there is a build to reconcile against.
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false)->complete();

        $filters = [];
        $tester = $this->runCommand([], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0), $filters);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertCount(1, $filters, 'the target is swept once');
        $this->assertStringContainsString('Sweep:', $tester->getDisplay());
        $this->assertStringContainsString('stale document(s) removed', $tester->getDisplay());
    }

    public function testTheSweepIsScopedToTheBasesOwnReferenceKey(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false)->complete();

        $filters = [];
        $this->runCommand([], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0), $filters);

        $filter = $filters[0];
        $this->assertArrayHasKey('reference_ids.al', $filter, 'only the base\'s own key is in scope');
        $this->assertSame(['$exists' => true], $filter['reference_ids.al']);
    }

    public function testNoSweepLeavesTheStaleDocumentsInPlace(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false)->complete();

        $filters = [];
        $tester = $this->runCommand(['--no-sweep' => false], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0), $filters);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([], $filters, 'nothing is deleted');
        $this->assertStringContainsString('Sweep disabled', $tester->getDisplay());
    }

    public function testTheSweepDoesNotRunWhenTheCoverageGateFails(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false)->complete();

        $filters = [];
        $tester = $this->runCommand([], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 10, 0, 0), $filters);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame([], $filters, 'a failed run must not delete anything');
    }

    public function testTheSweepDoesNotRunWhenTheRunProducedNoDocument(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false)->complete();

        $filters = [];
        // The percentage gate is off, so the run passes it — but it wrote
        // nothing, which is not a trustworthy basis for deleting.
        $tester = $this->runCommand(['--min-coverage' => '0'], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 0, 0, 0), $filters);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([], $filters);
        $this->assertStringContainsString('Sweep skipped', $tester->getDisplay());
    }

    public function testTheSweepIsSkippedWithoutABuildMarker(): void
    {
        $filters = [];
        $tester = $this->runCommand([], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0), $filters);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([], $filters, 'without a build there is nothing to reconcile against');
        $this->assertStringContainsString('no build marker', $tester->getDisplay());
    }
}
