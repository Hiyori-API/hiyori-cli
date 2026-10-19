<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\CombineBuild;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\Telemetry;
use MongoDB\UpdateResult;
use PHPUnit\Framework\TestCase;

/**
 * The build marker is what makes the sweep possible: every document a run
 * writes carries the id of the build that wrote it, and the run's identity
 * has to survive a crash so a resumed run is still the same build.
 */
final class CombineBuildTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/hiyori-combine-build-test-' . getmypid();
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
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testEveryBuildGetsItsOwnId(): void
    {
        $first = CombineBuild::newId();
        $second = CombineBuild::newId();

        $this->assertNotSame('', $first);
        $this->assertNotSame($first, $second);
    }

    public function testTheStampRecordsTheBaseAndTheBuild(): void
    {
        $stamp = CombineBuild::stamp('al', 'build-1');

        $this->assertSame(['al' => 'build-1'], $stamp[CombineBuild::FIELD]);
        $this->assertSame('build-1', CombineBuild::recordedFor($stamp, 'al'));
        $this->assertSame('', CombineBuild::recordedFor($stamp, 'k'), 'another base has no marker');
        $this->assertSame('', CombineBuild::recordedFor([], 'al'), 'an unmarked document has no marker');
    }

    public function testAFreshRunGetsANewBuildId(): void
    {
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized', false);

        $buildId = $checkpoint->buildId();
        $this->assertNotSame('', $buildId);
        $this->assertSame($buildId, (string) (CombineCheckpoint::peek()['build_id'] ?? ''));
    }

    public function testAResumedRunKeepsTheBuildIdOfTheRunItContinues(): void
    {
        $first = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized', false);
        $buildId = $first->buildId();

        $resumed = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized', true);

        $this->assertSame($buildId, $resumed->buildId(), 'the documents of the crashed run are still this run\'s');
        $this->assertFalse($resumed->resumedWithoutBuildId());
    }

    public function testAResumeFromACheckpointWithoutABuildIdIsFlagged(): void
    {
        // A checkpoint written before builds were stamped.
        (new Telemetry())->update(CombineCheckpoint::KEY, [
            'stage' => CombineCheckpoint::STAGE_FAILED,
            'strategy' => 'metadata_fuzzy',
            'base' => 'anilist',
            'target_collection' => 'organized',
        ]);

        $resumed = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized', true);

        $this->assertNotSame('', $resumed->buildId());
        $this->assertTrue(
            $resumed->resumedWithoutBuildId(),
            'the documents of the crashed run carry no marker, so a sweep must not run against them',
        );
    }

    public function testAFreshRunClearsTheFlagOfAnEarlierResume(): void
    {
        (new Telemetry())->update(CombineCheckpoint::KEY, [
            'stage' => CombineCheckpoint::STAGE_FAILED,
            'strategy' => 'metadata_fuzzy',
            'base' => 'anilist',
            'target_collection' => 'organized',
            'build_resumed_without_id' => true,
        ]);

        $fresh = CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized', false);

        $this->assertFalse(
            $fresh->resumedWithoutBuildId(),
            'a fresh run marks every document it writes, so its sweep may run',
        );
        $this->assertFalse(
            (bool) (CombineCheckpoint::peek()['build_resumed_without_id'] ?? true),
            'the flag is cleared in the stored state too',
        );
    }

    public function testTheWrittenDocumentCarriesTheBuildMarker(): void
    {
        $captured = null;
        $db = $this->createMock(Database::class);
        $db->method('allocateHiyoriId')->willReturn(4242);
        $db->method('mergeIntoOrganized')->willReturnCallback(
            function ($merged, $identifier, $value, $collection, $extra) use (&$captured): UpdateResult {
                $captured = $extra;

                return $this->createStub(UpdateResult::class);
            },
        );

        $double = new class () {
            use MergesEntries;

            /** @param array<string, mixed> $entry */
            public function call(Database $db, SourceConfigurationFactory $source, array $entry, string $collection, ?string $buildId): bool
            {
                return self::persistPassthrough($db, $source, $entry, $collection, $buildId);
            }
        };

        $entry = [
            'title' => 'Marked Anime',
            'reference_ids' => ['al' => '777001'],
        ];

        $this->assertTrue($double->call(
            $db,
            SourceConfigurationFactory::create()->setCurrent('anilist'),
            $entry,
            'organized_test',
            'build-42',
        ));

        $this->assertIsArray($captured);
        $this->assertSame(['al' => 'build-42'], $captured[CombineBuild::FIELD]);
    }

    public function testAWriteWithoutABuildIsLeftUnmarked(): void
    {
        $captured = null;
        $db = $this->createMock(Database::class);
        $db->method('allocateHiyoriId')->willReturn(4242);
        $db->method('mergeIntoOrganized')->willReturnCallback(
            function ($merged, $identifier, $value, $collection, $extra) use (&$captured): UpdateResult {
                $captured = $extra;

                return $this->createStub(UpdateResult::class);
            },
        );

        $double = new class () {
            use MergesEntries;

            /** @param array<string, mixed> $entry */
            public function call(Database $db, SourceConfigurationFactory $source, array $entry, string $collection, ?string $buildId): bool
            {
                return self::persistPassthrough($db, $source, $entry, $collection, $buildId);
            }
        };

        $double->call(
            $db,
            SourceConfigurationFactory::create()->setCurrent('anilist'),
            ['title' => 'Unmarked Anime', 'reference_ids' => ['al' => '777002']],
            'organized_test',
            null,
        );

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey(CombineBuild::FIELD, $captured);
    }
}
