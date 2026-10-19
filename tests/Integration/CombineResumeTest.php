<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Models\SourceConfiguration;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Combiner\Strategy\Concerns\IteratesBaseBatches;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Client;
use MongoDB\Collection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Keyset pagination + per-batch checkpointing, against a real collection.
 *
 * The base iteration used to hold one server cursor for the whole run; a crash
 * lost all progress and the next run started from zero. This drives the shared
 * batch generator directly: a full pass sees every row exactly once, and a
 * crash mid-batch leaves a checkpoint whose `last_id` is the end of the last
 * *completed* batch, so resuming continues from there.
 *
 * Runs against the throwaway `hiyori_test_resume` database, which is dropped
 * afterwards, and writes checkpoints to a temp directory rather than the live
 * `storage/state/`.
 */
final class CombineResumeTest extends TestCase
{
    private const DB_NAME = 'hiyori_test_resume';

    private const COLLECTION = 'combine_resume_base_test';

    private const TARGET = 'organized_test';

    private static Client $client;

    private static string $telemetryDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$telemetryDir = sys_get_temp_dir() . '/hiyori-telemetry-resume-' . getmypid();
        putenv('HIYORI_TELEMETRY_DIR=' . self::$telemetryDir);

        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }

        self::$client = new Client($_ENV['MONGODB_URL']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$client->selectDatabase(self::DB_NAME)->drop();

        putenv('HIYORI_TELEMETRY_DIR');
        foreach (glob(self::$telemetryDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$telemetryDir);
    }

    protected function setUp(): void
    {
        foreach (glob(self::$telemetryDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        $collection = $this->collection();
        $collection->drop();
        $documents = [];
        for ($i = 0; $i < 5; $i++) {
            $documents[] = [
                '_seq' => $i,
                'title' => 'Resume Row ' . $i,
                'reference_ids' => ['al_manga' => '9000' . $i],
            ];
        }
        $collection->insertMany($documents);
    }

    private function collection(): Collection
    {
        return self::$client->selectDatabase(self::DB_NAME)->selectCollection(self::COLLECTION);
    }

    /**
     * A Database whose `getCollection()` returns the real test collection. The
     * mocked class avoids connecting through the production `hiyori` database.
     */
    private function database(): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('getCollection')->willReturn($this->collection());

        return $db;
    }

    /** @return array<string, int> */
    private function counters(): array
    {
        return [
            'examined' => 0,
            'merged' => 0,
            'passthrough' => 0,
            'unresolved_base_id' => 0,
        ];
    }

    /** @param array<string, int> $counters */
    private function batches(Database $db, CombineCheckpoint $checkpoint, array &$counters, int $batchSize): \Generator
    {
        $double = new class () {
            use IteratesBaseBatches;

            /** @param array<string, mixed> $counters */
            public function call(Database $db, string $table, CombineCheckpoint $checkpoint, array &$counters, int $batchSize): \Generator
            {
                return self::baseEntryBatches($db, $table, $checkpoint, $counters, $batchSize);
            }
        };

        return $double->call($db, self::COLLECTION, $checkpoint, $counters, $batchSize);
    }

    public function testAFullPassSeesEveryRowOnceAndCompletes(): void
    {
        $counters = $this->counters();
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', self::COLLECTION, self::TARGET, false);

        $seen = [];
        foreach ($this->batches($this->database(), $checkpoint, $counters, 2) as $entry) {
            $seen[] = (int) $entry['_seq'];
            $counters['examined']++;
            $counters['merged']++;
        }
        $checkpoint->complete();

        $this->assertSame([0, 1, 2, 3, 4], $seen, 'keyset pagination visits every row in _id order, once');
        $this->assertSame(5, $counters['examined']);
        $this->assertSame(5, $checkpoint->counters($this->counters())['examined']);

        $state = CombineCheckpoint::peek();
        $this->assertSame('done', $state['stage']);
        $this->assertSame(5, $state['written']);
    }

    public function testACrashMidBatchLeavesAResumableCheckpoint(): void
    {
        $db = $this->database();
        $counters = $this->counters();
        $checkpoint = CombineCheckpoint::begin('metadata_fuzzy', self::COLLECTION, self::TARGET, false);

        $seen = [];
        try {
            foreach ($this->batches($db, $checkpoint, $counters, 2) as $entry) {
                $seen[] = (int) $entry['_seq'];
                $counters['examined']++;
                $counters['merged']++;
                if (count($seen) === 3) {
                    // Crash on the first row of the second batch: the batch is
                    // not committed, so only the first batch survives.
                    throw new RuntimeException('simulated crash');
                }
            }
            $this->fail('the simulated crash should have propagated');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated crash', $e->getMessage());
        }

        $this->assertSame([0, 1, 2], $seen);
        $state = CombineCheckpoint::peek();
        $this->assertSame('failed', $state['stage']);
        $this->assertSame(2, $state['examined'], 'the interrupted batch is not counted');
        $this->assertSame(2, $state['written']);
        $this->assertNotNull($state['last_id']);

        // Resume: counters and last_id come back, and the rest of the base is
        // processed. The row the crash was on is re-processed, which is safe
        // because every write is an upsert keyed by the base reference.
        $resume = CombineCheckpoint::begin('metadata_fuzzy', self::COLLECTION, self::TARGET, true);
        $resumeCounters = $resume->counters($this->counters());
        $this->assertSame(2, $resumeCounters['examined'], 'counters continue, they do not restart');

        $resumed = [];
        foreach ($this->batches($db, $resume, $resumeCounters, 2) as $entry) {
            $resumed[] = (int) $entry['_seq'];
            $resumeCounters['examined']++;
            $resumeCounters['merged']++;
        }
        $resume->complete();

        $this->assertSame([2, 3, 4], $resumed, 'resume continues after the last committed batch');
        $this->assertSame([0, 1, 2, 3, 4], array_values(array_unique(array_merge($seen, $resumed))));
        $this->assertSame(5, $resumeCounters['examined']);
        $this->assertSame('done', CombineCheckpoint::peek()['stage']);
    }

    /**
     * The strategies drive the shared generator; this proves the wiring end to
     * end (open the checkpoint, iterate in batches, close it as done) for one
     * of them, without writing to the production `hiyori` database.
     */
    public function testAStrategyRunMarksItsCheckpointDone(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getCollection')->willReturn($this->collection());
        $db->method('exists')->willReturn(false);

        $stats = RelationalMappingStrategy::process(
            $db,
            ConsoleFactory::create(),
            StrategyConfigurationFactory::create()
                ->setStrategy(RelationalMappingStrategy::NAME)
                ->setConfig(['collection' => self::TARGET]),
            $this->sourceFactory(),
        );

        $this->assertSame(5, $stats->examined);
        $this->assertSame(0, $stats->merged);
        $this->assertSame(0, $stats->unresolvedBaseId);

        $state = CombineCheckpoint::peek();
        $this->assertSame('done', $state['stage']);
        $this->assertSame('relational_mapping', $state['strategy']);
        $this->assertSame(self::COLLECTION, $state['base']);
        $this->assertSame(self::TARGET, $state['target_collection']);
        $this->assertSame(5, $state['examined']);
    }

    private function sourceFactory(): SourceConfigurationFactory
    {
        $factory = SourceConfigurationFactory::create();
        $sources = [
            'anilist_manga_smoke' => new SourceConfiguration([
                'name' => 'AniList Manga Smoke',
                'table' => self::COLLECTION,
                'shorthand' => 'al_manga',
                'json_id' => 'id',
                'ingestion_service' => \Hiyori\Service\Ingestion\Source\AniListMangaIngestion::class,
            ]),
            'kitsu_manga_smoke' => new SourceConfiguration([
                'name' => 'Kitsu Manga Smoke',
                'table' => 'combine_resume_candidate_test',
                'shorthand' => 'k_manga',
                'json_id' => 'id',
                'ingestion_service' => \Hiyori\Service\Ingestion\Source\KitsuMangaIngestion::class,
            ]),
        ];

        $property = new \ReflectionProperty(SourceConfigurationFactory::class, 'sources');
        $property->setValue($factory, $sources);

        return $factory->setCurrent('anilist_manga_smoke');
    }
}
