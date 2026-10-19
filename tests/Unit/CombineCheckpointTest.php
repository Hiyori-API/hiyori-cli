<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Telemetry;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The checkpoint is what makes a combine run resumable. These tests drive it
 * through the state directory `Telemetry` would use, with `HIYORI_TELEMETRY_DIR`
 * pointed at a throwaway directory so nothing lands in the live
 * `storage/state/` the dashboard polls.
 */
final class CombineCheckpointTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/hiyori-checkpoint-test-' . getmypid();
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
        // A clean directory per test: the checkpoint is last-write-wins.
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed> */
    private function counters(): array
    {
        return [
            'examined' => 0,
            'merged' => 0,
            'passthrough' => 0,
            'unresolved_base_id' => 0,
            'total_merged' => 0,
        ];
    }

    public function testBeginWritesARunningMarkerBeforeAnyWork(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);

        $state = CombineCheckpoint::peek();
        $this->assertSame('running', $state['stage']);
        $this->assertSame('metadata_fuzzy', $state['strategy']);
        $this->assertSame('anilist_manga', $state['base']);
        $this->assertSame('organized_manga', $state['target_collection']);
        $this->assertNull($state['last_id']);
        $this->assertSame(0, $state['examined']);
        $this->assertSame(0, $state['written']);
        $this->assertArrayHasKey('updated_at', $state, 'Telemetry stamps updated_at');

        // The dashboard reads combine-latest; the phase marker is the only way
        // it can tell a live run from the last finished one.
        $latest = (new Telemetry())->read('combine-latest');
        $this->assertSame('running', $latest['phase']);
    }

    public function testRecordPersistsTheLastIdAndCounters(): void
    {
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);
        $id = new ObjectId();

        $counters = $this->counters();
        $counters['examined'] = 500;
        $counters['merged'] = 120;
        $counters['passthrough'] = 380;
        $counters['total_merged'] = 140;
        $checkpoint->record($counters, $id);

        $state = CombineCheckpoint::peek();
        $this->assertSame('running', $state['stage']);
        $this->assertSame((string) $id, $state['last_id']);
        $this->assertSame('objectid', $state['last_id_kind']);
        $this->assertSame(500, $state['examined']);
        $this->assertSame(120, $state['merged']);
        $this->assertSame(380, $state['passthrough']);
        $this->assertSame(500, $state['written'], 'written follows merged + passthrough');
        $this->assertSame($counters, $state['counters']);
    }

    public function testResumeRestoresCountersAndLastId(): void
    {
        $first = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);
        $id = new ObjectId();
        $counters = $this->counters();
        $counters['examined'] = 500;
        $counters['merged'] = 120;
        $counters['passthrough'] = 380;
        $first->record($counters, $id);

        $resumed = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', true);
        $restored = $resumed->counters($this->counters());

        $this->assertSame(500, $restored['examined']);
        $this->assertSame(120, $restored['merged']);
        $this->assertSame(380, $restored['passthrough']);
        $this->assertInstanceOf(ObjectId::class, $resumed->lastId());
        $this->assertSame((string) $id, (string) $resumed->lastId());
    }

    public function testResumeIgnoresCountersFromAnotherStrategy(): void
    {
        $first = CombineCheckpoint::begin('graph_merge', 'anilist_manga', 'organized_manga', false);
        $counters = $this->counters();
        $counters['examined'] = 10;
        $counters['cluster_sizes'] = [2 => 3];
        $first->record($counters, new ObjectId());

        // A different strategy would never be allowed to resume this checkpoint,
        // but the counter restore is defensive anyway: unknown keys are dropped.
        $restored = (CombineCheckpoint::begin('graph_merge', 'anilist_manga', 'organized_manga', true))
            ->counters($this->counters());

        $this->assertSame(10, $restored['examined']);
        $this->assertArrayNotHasKey('cluster_sizes', $restored);
    }

    public function testCompleteFlipsTheMarkerToDoneSoResumeRefuses(): void
    {
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);
        $checkpoint->complete();

        $this->assertSame('done', CombineCheckpoint::peek()['stage']);
        $this->assertSame('done', (new Telemetry())->read('combine-latest')['phase']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already done');
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', true);
    }

    public function testFailMarksFailedButKeepsTheResumePoint(): void
    {
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);
        $id = new ObjectId();
        $counters = $this->counters();
        $counters['examined'] = 500;
        $checkpoint->record($counters, $id);
        $checkpoint->fail('cursor id not found');

        $state = CombineCheckpoint::peek();
        $this->assertSame('failed', $state['stage']);
        $this->assertSame((string) $id, $state['last_id'], 'a failed run must still say where it stopped');
        $this->assertSame('cursor id not found', $state['error']);

        // A failed checkpoint is resumable.
        $resumed = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', true);
        $this->assertSame(500, $resumed->counters($this->counters())['examined']);
    }

    public function testResumeRefusesWhenTheBaseStrategyOrTargetDiffer(): void
    {
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);

        $this->assertStringContainsString(
            'base',
            (string) CombineCheckpoint::mismatch(CombineCheckpoint::peek(), 'metadata_fuzzy', 'anilist', 'organized_manga'),
        );
        $this->assertStringContainsString(
            'strategy',
            (string) CombineCheckpoint::mismatch(CombineCheckpoint::peek(), 'graph_merge', 'anilist_manga', 'organized_manga'),
        );
        $this->assertStringContainsString(
            'target_collection',
            (string) CombineCheckpoint::mismatch(CombineCheckpoint::peek(), 'metadata_fuzzy', 'anilist_manga', 'organized'),
        );
        $this->assertNull(CombineCheckpoint::mismatch(CombineCheckpoint::peek(), 'metadata_fuzzy', 'anilist_manga', 'organized_manga'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different run');
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist', 'organized_manga', true);
    }

    public function testResumeWithoutACheckpointExplainsItself(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no checkpoint');
        CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', true);
    }

    public function testAFreshRunOverwritesTheStoredCheckpoint(): void
    {
        $first = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);
        $counters = $this->counters();
        $counters['examined'] = 500;
        $first->record($counters, new ObjectId());

        $fresh = CombineCheckpoint::begin('metadata_fuzzy', 'anilist_manga', 'organized_manga', false);

        $this->assertNull($fresh->lastId());
        $this->assertSame(0, $fresh->counters($this->counters())['examined'], 'a run without --resume starts from zero');
    }
}
