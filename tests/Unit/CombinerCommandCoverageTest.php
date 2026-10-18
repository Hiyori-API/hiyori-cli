<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Commands\CombinerCommand;
use Hiyori\Hiyori;
use Hiyori\Service\Combiner\CombineStats;
use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `hiyori combine` must fail when a run writes documents for too small a share
 * of its base rows, instead of reporting success over any amount of loss.
 *
 * The combiner is mocked: `Hiyori::combine()` returns a fabricated
 * CombineStats, which is exactly the contract the real strategies honour. The
 * target collection is a non-Migrator kind so the command never probes a live
 * database.
 */
final class CombinerCommandCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        // Never contend with a real combine: the production lock is held for the
        // whole duration of a rebuild.
        $_ENV['COMBINE_LOCK_NAME'] = 'combine-test-' . getmypid();
    }

    protected function tearDown(): void
    {
        unset($_ENV['COMBINE_LOCK_NAME']);
    }

    /**
     * A Migrator with every known migration stubbed as applied, so `pending()`
     * returns [] without touching MongoDB. (`Migrator` is final, so it cannot
     * be doubled.)
     */
    private function migrator(): Migrator
    {
        $_ENV['MONGODB_URL'] ??= 'mongodb://localhost:27017';

        return new Migrator(
            new Database(SerializerBuilder::create()->build()),
            'hiyori_test_coverage',
            [1 => 'applied', 2 => 'applied', 3 => 'applied', 4 => 'applied'],
        );
    }

    /**
     * @param array<string, mixed> $arguments extra CLI arguments
     */
    private function runCommand(array $arguments, CombineStats $stats): CommandTester
    {
        $hiyori = $this->createMock(Hiyori::class);
        $hiyori->method('getMigrator')->willReturn($this->migrator());
        $hiyori->expects($this->once())
            ->method('combine')
            ->willReturn($stats);

        $tester = new CommandTester(new CombinerCommand($hiyori));
        $tester->execute(array_merge(['base' => 'anilist', '--into' => 'coverage_test'], $arguments));

        return $tester;
    }

    public function testFailsWhenCoverageIsBelowTheFloor(): void
    {
        $tester = $this->runCommand([], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 10, 0, 0));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Coverage: 10 of 100 base rows covered (10.0%)', $tester->getDisplay());
        $this->assertStringContainsString('Coverage gate FAILED', $tester->getDisplay());
    }

    public function testPassesAtOrAboveTheFloor(): void
    {
        $tester = $this->runCommand([], CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Coverage: 95 of 100 base rows covered (95.0%)', $tester->getDisplay());
        $this->assertStringNotContainsString('Coverage gate FAILED', $tester->getDisplay());
    }

    public function testEmptyBaseDoesNotPassEvenWithTheFloorDisabled(): void
    {
        $tester = $this->runCommand(
            ['--min-coverage' => '0'],
            CombineStats::forRun('metadata_fuzzy', 'anilist', 0, 0, 0, 0),
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('examined 0 base rows', $tester->getDisplay());
    }

    public function testPassthroughRunsUseTheSameGate(): void
    {
        $tester = $this->runCommand(
            ['--passthrough' => true],
            CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 30, 5, 10),
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('(35.0%)', $tester->getDisplay());
    }

    public function testCustomFloorIsHonoured(): void
    {
        $stats = CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 50, 0, 0);

        $this->assertSame(Command::FAILURE, $this->runCommand(['--min-coverage' => '60'], $stats)->getStatusCode());
        $this->assertSame(Command::SUCCESS, $this->runCommand(['--min-coverage' => '50'], $stats)->getStatusCode());
    }

    public function testZeroFloorDisablesThePercentageComparison(): void
    {
        $tester = $this->runCommand(
            ['--min-coverage' => '0'],
            CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 10, 0, 0),
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Coverage gate disabled', $tester->getDisplay());
    }

    public function testRejectsAnOutOfRangeFloorBeforeCombining(): void
    {
        $hiyori = $this->createMock(Hiyori::class);
        $hiyori->method('getMigrator')->willReturn($this->migrator());
        $hiyori->expects($this->never())->method('combine');

        $tester = new CommandTester(new CombinerCommand($hiyori));
        $tester->execute(['base' => 'anilist', '--into' => 'coverage_test', '--min-coverage' => '101']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('--min-coverage must be an integer between 0 and 100', $tester->getDisplay());
    }

    public function testArbitraryConfigCannotAffectTheCoverageResult(): void
    {
        // `--config` merges arbitrary JSON into the strategy config. Coverage
        // now comes from the return value, so no config key can redirect it.
        $tester = $this->runCommand(
            ['--config' => '{"stats_sink":"system","coverage":100}'],
            CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 90, 0, 0),
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Coverage: 90 of 100 base rows covered (90.0%)', $tester->getDisplay());
    }
}
