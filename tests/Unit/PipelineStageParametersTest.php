<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Commands\CombineDedupeCommand;
use Hiyori\Commands\CombineDupesCommand;
use Hiyori\Commands\CombinerCommand;
use Hiyori\Commands\HiyoriIdCommand;
use Hiyori\Commands\MigrateCommand;
use Hiyori\Commands\PipelineCommand;
use Hiyori\Commands\ReleaseCommand;
use Hiyori\Hiyori;
use Hiyori\Service\Pipeline\PipelineChecks;
use Hiyori\Service\Pipeline\PreconditionResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every stage parameter the pipeline builds must be accepted by the real
 * command's definition. This catches a typo in an option name without running
 * any command against a database.
 */
final class PipelineStageParametersTest extends TestCase
{
    public function testEveryStageParameterBindsToTheRealCommandDefinition(): void
    {
        $hiyori = $this->createMock(Hiyori::class);
        $application = new Application();
        $application->add(new MigrateCommand($hiyori));
        $application->add(new CombinerCommand($hiyori));
        $application->add(new HiyoriIdCommand($hiyori));
        $application->add(new CombineDupesCommand($hiyori));
        $application->add(new CombineDedupeCommand($hiyori));
        $application->add(new ReleaseCommand());

        $checks = new class () implements PipelineChecks {
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
                return PreconditionResult::pass('no_pending_migrations', 'stub');
            }

            public function collectionHasDocuments(string $collection): PreconditionResult
            {
                return PreconditionResult::pass('collection_not_empty:' . $collection, 'stub');
            }
        };

        $command = new PipelineCommand(
            $hiyori,
            $application,
            $checks,
            sys_get_temp_dir() . '/hiyori-pipeline-unused-' . bin2hex(random_bytes(6)),
        );
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true, '--json' => true, '--min-coverage' => '45', '--tag' => 'data-2026.42']);

        $record = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($record);
        $this->assertCount(7, $record['plan']);

        foreach ($record['plan'] as $stage) {
            $definition = $application->find($stage['command'])->getDefinition();
            $input = new ArrayInput($stage['params']);
            $input->bind($definition);
            $this->addToAssertionCount(1);
        }

        $combine = $record['plan'][1]['params'];
        $this->assertSame('anilist', $combine['base']);
        $this->assertTrue($combine['--passthrough']);
        $this->assertSame('45', $combine['--min-coverage']);
        $this->assertArrayNotHasKey('--into', $combine);

        $manga = $record['plan'][2]['params'];
        $this->assertSame('anilist_manga', $manga['base']);
        $this->assertSame('organized_manga', $manga['--into']);

        $dedupe = $record['plan'][5]['params'];
        $this->assertSame('mal,al,mal_manga,al_manga', $dedupe['--primary']);
    }
}
