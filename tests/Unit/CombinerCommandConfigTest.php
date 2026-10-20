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
 * `hiyori combine --config` used to accept any JSON and hand the strategy
 * whatever it liked. A key the strategy does not read was silently ignored,
 * so `--config '{"thresold":80}'` looked like a setting and did nothing.
 *
 * The command now refuses such a config before it touches the database, with
 * the key and the accepted keys in the message.
 */
final class CombinerCommandConfigTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/hiyori-combine-config-test-' . getmypid();
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
        $_ENV['COMBINE_LOCK_NAME'] = 'combine-config-test-' . getmypid();
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

        $db = new Database(SerializerBuilder::create()->build());
        // Every known migration counts as applied. Hard-coding the list
        // made each new migration look pending, and the command refused to
        // run until the test was edited.
        $stub = array_fill_keys(array_keys((new Migrator($db, 'hiyori_test_config', []))->versions()), 'applied');

        return new Migrator($db, 'hiyori_test_config', $stub);
    }

    /**
     * Run the command with the combiner mocked.
     *
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments, ?CombineStats $stats = null): CommandTester
    {
        $hiyori = $this->createMock(Hiyori::class);
        $hiyori->method('getMigrator')->willReturn($this->migrator());
        if ($stats === null) {
            $hiyori->expects($this->never())->method('combine');
        } else {
            $hiyori->expects($this->once())->method('combine')->willReturn($stats);
        }

        $tester = new CommandTester(new CombinerCommand($hiyori));
        $tester->execute(array_merge(['base' => 'anilist', '--into' => 'config_test'], $arguments));

        return $tester;
    }

    public function testATypedKeyIsRefused(): void
    {
        $tester = $this->runCommand(['--config' => '{"thresold":80}']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown config key "thresold"', $tester->getDisplay());
        $this->assertStringContainsString('strategy "metadata_fuzzy"', $tester->getDisplay());
        $this->assertStringContainsString('Accepted keys:', $tester->getDisplay());
    }

    public function testTheRefusalHappensBeforeTheDatabaseIsTouched(): void
    {
        $hiyori = $this->createMock(Hiyori::class);
        $hiyori->expects($this->never())->method('getMigrator');
        $hiyori->expects($this->never())->method('combine');

        $tester = new CommandTester(new CombinerCommand($hiyori));
        $tester->execute(['base' => 'anilist', '--into' => 'config_test', '--config' => '{"thresold":80}']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown config key "thresold"', $tester->getDisplay());
    }

    public function testAKeyOfAnotherStrategyIsRefused(): void
    {
        $tester = $this->runCommand(['--config' => '{"ml_threshold":0.6}']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown config key "ml_threshold"', $tester->getDisplay());
    }

    public function testTheSameKeyIsAcceptedByTheStrategyThatReadsIt(): void
    {
        $tester = $this->runCommand(
            ['--strategy' => 'metadata_ml', '--config' => '{"ml_threshold":0.6,"metadata_floor":55}'],
            CombineStats::forRun('metadata_ml', 'anilist', 100, 95, 0, 0),
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheDocumentedWeightsStillWork(): void
    {
        $tester = $this->runCommand(
            ['--weights' => '{"title":25}'],
            CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0),
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheDocumentedThresholdStillWorks(): void
    {
        $tester = $this->runCommand(
            ['--threshold' => '80'],
            CombineStats::forRun('metadata_fuzzy', 'anilist', 100, 95, 0, 0),
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testMalformedJsonIsStillRefused(): void
    {
        $tester = $this->runCommand(['--config' => 'not json']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('--config must be a valid JSON object', $tester->getDisplay());
    }
}
