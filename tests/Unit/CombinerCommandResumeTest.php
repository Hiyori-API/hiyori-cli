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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `combine --resume` must continue a crashed run from the stored checkpoint,
 * and refuse (with a clear reason) whenever the checkpoint does not belong to
 * the run being asked for. Without `--resume` the command must behave exactly
 * as before.
 *
 * The combiner itself is mocked: `Hiyori::combine()` captures the config the
 * command would pass to the strategy. The target collection is a non-Migrator
 * kind so the command never probes a live database.
 */
final class CombinerCommandResumeTest extends TestCase
{
    private const TARGET = 'resume_test';

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/hiyori-combine-resume-test-' . getmypid();
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
        $_ENV['COMBINE_LOCK_NAME'] = 'combine-resume-test-' . getmypid();
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
     * returns [] without touching MongoDB. (`Migrator` is final, so it cannot
     * be doubled.)
     */
    private function migrator(): Migrator
    {
        $_ENV['MONGODB_URL'] ??= 'mongodb://localhost:27017';

        return new Migrator(
            new Database(SerializerBuilder::create()->build()),
            'hiyori_test_resume_cmd',
            [1 => 'applied', 2 => 'applied', 3 => 'applied', 4 => 'applied', 5 => 'applied'],
        );
    }

    /**
     * @param array<string, mixed> $arguments extra CLI arguments
     * @param array<string, mixed>|null $captured  receives the config passed to combine()
     */
    private function runCommand(array $arguments, ?array &$captured, bool $expectCombine = true): CommandTester
    {
        $hiyori = $this->createMock(Hiyori::class);
        $hiyori->method('getMigrator')->willReturn($this->migrator());

        $combine = $hiyori->expects($expectCombine ? $this->once() : $this->never())->method('combine');
        if ($expectCombine) {
            $combine->willReturnCallback(function (string $base, string $strategy, array $config) use (&$captured): CombineStats {
                $captured = $config;

                return CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0);
            });
        }

        $tester = new CommandTester(new CombinerCommand($hiyori));
        $tester->execute(array_merge(['base' => 'anilist', '--into' => self::TARGET], $arguments));

        return $tester;
    }

    public function testResumeContinuesWhenTheCheckpointMatches(): void
    {
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false);
        $counters = ['examined' => 100, 'merged' => 60, 'passthrough' => 30, 'unresolved_base_id' => 0];
        $checkpoint->record($counters, new \MongoDB\BSON\ObjectId());

        $captured = null;
        $tester = $this->runCommand(['--resume' => true], $captured);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($captured['resume'] ?? null, 'the strategy must be told to resume');
        $this->assertStringContainsString('Resuming', $tester->getDisplay());
    }

    public function testResumeWithoutACheckpointFailsWithoutCombining(): void
    {
        $captured = null;
        $tester = $this->runCommand(['--resume' => true], $captured, false);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('no checkpoint', $tester->getDisplay());
    }

    public function testResumeRefusesAFinishedCheckpoint(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false)->complete();

        $captured = null;
        $tester = $this->runCommand(['--resume' => true], $captured, false);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('already done', $tester->getDisplay());
    }

    public function testResumeRefusesADifferentBaseStrategyOrTarget(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'kitsu', self::TARGET, false);

        $captured = null;
        $tester = $this->runCommand(['--resume' => true], $captured, false);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('different run', $tester->getDisplay());
        $this->assertStringContainsString('base', $tester->getDisplay());

        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized', false);
        $tester = $this->runCommand(['--resume' => true], $captured, false);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('target_collection', $tester->getDisplay());
    }

    public function testWithoutResumeTheRunStartsFresh(): void
    {
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false);
        $checkpoint->record(['examined' => 100, 'merged' => 60, 'passthrough' => 30, 'unresolved_base_id' => 0], new \MongoDB\BSON\ObjectId());

        $captured = null;
        $tester = $this->runCommand([], $captured);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFalse($captured['resume'] ?? null, 'a full run must not resume');
    }

    public function testAConfigKeyCannotInjectResume(): void
    {
        // `--config` merges arbitrary JSON into the strategy config; a stray
        // `resume` key must not start a partial run behind the command's back.
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', self::TARGET, false);

        $captured = null;
        $tester = $this->runCommand(['--config' => '{"resume":true}'], $captured);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFalse($captured['resume'] ?? null);
    }
}
