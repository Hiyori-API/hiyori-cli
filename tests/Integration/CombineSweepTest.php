<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Models\SourceConfiguration;
use Hiyori\Service\Combiner\CombineBuild;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Combiner\CombineSweeper;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\UpdateResult;
use PHPUnit\Framework\TestCase;

/**
 * The end of the never-deleting combiner: a run must reconcile the documents
 * it is responsible for, and must never touch a document another base owns.
 *
 * The rules under test:
 *
 *   - a document carrying the base's own reference key but not this build's
 *     marker is stale and is deleted;
 *   - a document carrying only another base's key is untouched;
 *   - a document another base wrote is untouched even though it also carries
 *     this base's key;
 *   - a document written before builds were stamped is reconciled too (the
 *     first sweep after the upgrade clears the old debris);
 *   - the sweep is idempotent: running it twice deletes nothing the second
 *     time.
 *
 * Everything runs against the throwaway `hiyori_test_sweep` database, which
 * is dropped afterwards, and the checkpoints go to a temp directory rather
 * than the live `storage/state/`.
 */
final class CombineSweepTest extends TestCase
{
    private const DB_NAME = 'hiyori_test_sweep';

    private const BASE_COLLECTION = 'combine_sweep_base_test';

    private const CANDIDATE_COLLECTION = 'combine_sweep_candidate_test';

    private const TARGET = 'combine_sweep_target_test';

    private const SHORTHAND = 'al';

    private const OTHER_SHORTHAND = 'k';

    private static Client $client;

    private static string $telemetryDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$telemetryDir = sys_get_temp_dir() . '/hiyori-telemetry-sweep-' . getmypid();
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

        self::$client->selectDatabase(self::DB_NAME)->drop();
    }

    private function target(): Collection
    {
        return self::$client->selectDatabase(self::DB_NAME)->selectCollection(self::TARGET);
    }

    private function base(): Collection
    {
        return self::$client->selectDatabase(self::DB_NAME)->selectCollection(self::BASE_COLLECTION);
    }

    /**
     * The `_id` of every document in the target, sorted.
     *
     * @return list<string>
     */
    private function targetIds(): array
    {
        $ids = [];
        foreach ($this->target()->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']]) as $doc) {
            $ids[] = (string) $doc['_id'];
        }
        sort($ids);

        return $ids;
    }

    /**
     * The title of every document in the target, sorted.
     *
     * @return list<string>
     */
    private function targetTitles(): array
    {
        $titles = [];
        foreach ($this->target()->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']]) as $doc) {
            $titles[] = (string) ($doc['title'] ?? '');
        }
        sort($titles);

        return $titles;
    }

    public function testTheSweepOnlyDeletesTheBasesOwnStaleDocuments(): void
    {
        $this->target()->insertMany([
            // This base's document, written by an older build: stale.
            ['_id' => 'stale', 'title' => 'Stale', 'reference_ids' => [self::SHORTHAND => '1'], CombineBuild::FIELD => [self::SHORTHAND => 'old-build']],
            // This base's document, written by this build: kept.
            ['_id' => 'current', 'title' => 'Current', 'reference_ids' => [self::SHORTHAND => '2'], CombineBuild::FIELD => [self::SHORTHAND => 'build-1']],
            // Written before builds were stamped: reconciled as well.
            ['_id' => 'legacy', 'title' => 'Legacy', 'reference_ids' => [self::SHORTHAND => '3']],
            // Another base's document: its identity is not this base's key.
            ['_id' => 'other-base', 'title' => 'Other base', 'reference_ids' => [self::OTHER_SHORTHAND => '4'], CombineBuild::FIELD => [self::OTHER_SHORTHAND => 'build-9']],
            // Another base wrote this one, even though it also carries this
            // base's key: not this base's to delete.
            ['_id' => 'other-base-owner', 'title' => 'Other base owner', 'reference_ids' => [self::SHORTHAND => '5', self::OTHER_SHORTHAND => '6'], CombineBuild::FIELD => [self::OTHER_SHORTHAND => 'build-9']],
            // Carries both keys but this base wrote it: kept.
            ['_id' => 'both-keys', 'title' => 'Both keys', 'reference_ids' => [self::SHORTHAND => '7', 'mal' => '8'], CombineBuild::FIELD => [self::SHORTHAND => 'build-1']],
        ]);

        $deleted = CombineSweeper::sweep($this->target(), self::SHORTHAND, 'build-1');

        $this->assertSame(2, $deleted, 'the stale and the legacy document go');
        $this->assertSame(
            ['both-keys', 'current', 'other-base', 'other-base-owner'],
            $this->targetIds(),
            'the documents of the other bases and the ones this build wrote stay',
        );
    }

    public function testTheSweepIsIdempotent(): void
    {
        $this->target()->insertMany([
            ['_id' => 'stale', 'title' => 'Stale', 'reference_ids' => [self::SHORTHAND => '1'], CombineBuild::FIELD => [self::SHORTHAND => 'old-build']],
            ['_id' => 'current', 'title' => 'Current', 'reference_ids' => [self::SHORTHAND => '2'], CombineBuild::FIELD => [self::SHORTHAND => 'build-1']],
        ]);

        $this->assertSame(1, CombineSweeper::sweep($this->target(), self::SHORTHAND, 'build-1'));
        $this->assertSame(0, CombineSweeper::sweep($this->target(), self::SHORTHAND, 'build-1'), 'a second sweep has nothing left to delete');
        $this->assertSame(0, CombineSweeper::countStale($this->target(), self::SHORTHAND, 'build-1'));
        $this->assertSame(['current'], $this->targetIds());
    }

    public function testTheSweepOfAnotherBaseDoesNotTouchThisBasesDocuments(): void
    {
        $this->target()->insertMany([
            ['_id' => 'mine', 'title' => 'Mine', 'reference_ids' => [self::SHORTHAND => '1'], CombineBuild::FIELD => [self::SHORTHAND => 'build-1']],
            ['_id' => 'theirs', 'title' => 'Theirs', 'reference_ids' => [self::OTHER_SHORTHAND => '2'], CombineBuild::FIELD => [self::OTHER_SHORTHAND => 'build-9']],
        ]);

        $this->assertSame(0, CombineSweeper::sweep($this->target(), self::OTHER_SHORTHAND, 'build-9'), 'the other base has nothing stale');
        $this->assertSame(['mine', 'theirs'], $this->targetIds());
    }

    /**
     * The whole loop: a real strategy run stamps the build id on every
     * document it writes, and the sweep that follows removes the documents
     * the run did not write.
     */
    public function testAStrategyRunStampsTheBuildAndTheSweepRemovesWhatItDidNotWrite(): void
    {
        // Three base rows, all of them still present.
        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $rows[] = [
                'title' => 'Sweep Row ' . $i,
                'type' => 'tv',
                'episodes' => 12,
                'status' => 'finished',
                'season' => 'spring',
                'year' => 2020,
                'images' => [],
                'references' => [],
                'tags' => [],
                'reference_ids' => [self::SHORTHAND => '9000' . $i],
            ];
        }
        $this->base()->insertMany($rows);

        // The target holds debris of earlier runs: a document of a base row
        // that no longer exists, a legacy document without a marker, and a
        // document of another base.
        $this->target()->insertMany([
            ['_id' => 'vanished', 'title' => 'Vanished', 'reference_ids' => [self::SHORTHAND => '99999'], CombineBuild::FIELD => [self::SHORTHAND => 'old-build']],
            ['_id' => 'legacy', 'title' => 'Legacy', 'reference_ids' => [self::SHORTHAND => '88888']],
            ['_id' => 'other-base', 'title' => 'Other base', 'reference_ids' => [self::OTHER_SHORTHAND => '77777'], CombineBuild::FIELD => [self::OTHER_SHORTHAND => 'other-build']],
        ]);

        $stats = RelationalMappingStrategy::process(
            $this->database(),
            ConsoleFactory::create(),
            StrategyConfigurationFactory::create()
                ->setStrategy(RelationalMappingStrategy::NAME)
                ->setConfig(['collection' => self::TARGET]),
            $this->sourceFactory(),
        );

        $this->assertSame(3, $stats->examined);
        $this->assertSame(3, $stats->merged);

        $state = CombineCheckpoint::peek();
        $buildId = (string) ($state['build_id'] ?? '');
        $this->assertNotSame('', $buildId, 'the run records the build it stamped');

        // Every document the run wrote carries the marker.
        $stamped = $this->target()->countDocuments([
            CombineBuild::FIELD => [self::SHORTHAND => $buildId],
        ]);
        $this->assertSame(3, $stamped, 'every document the run wrote carries the build id');

        $deleted = CombineSweeper::sweep($this->target(), self::SHORTHAND, $buildId);

        $this->assertSame(2, $deleted, 'the vanished and the legacy document go');
        $this->assertSame(
            ['Other base', 'Sweep Row 1', 'Sweep Row 2', 'Sweep Row 3'],
            $this->targetTitles(),
            'the other base keeps its document, the run keeps its own',
        );
    }

    /**
     * A Database whose `getCollection()` returns the scratch base collection
     * and whose writes land in the scratch target collection. The production
     * `hiyori` database is never touched.
     */
    private function database(): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('getCollection')->willReturn($this->base());
        $db->method('exists')->willReturn(true);
        $db->method('allocateHiyoriId')->willReturn(1);
        $db->method('get')->willReturnCallback(
            static fn (string $source, string $shorthand, string $id): array => [
                'title' => 'Candidate ' . $id,
                'synonyms' => [],
                'type' => 'tv',
                'episodes' => 12,
                'status' => 'finished',
                'season' => 'spring',
                'year' => 2020,
                'images' => [],
                'references' => [],
                'tags' => [],
                'reference_ids' => [$shorthand => $id],
            ],
        );
        $db->method('mergeIntoOrganized')->willReturnCallback(
            function ($entry, $identifier, $value, $collection, $extra): UpdateResult {
                $document = array_merge($entry->toArray(), $extra);
                $this->target()->replaceOne(
                    ['reference_ids.' . $identifier => $value],
                    $document,
                    ['upsert' => true],
                );

                return $this->createStub(UpdateResult::class);
            },
        );

        return $db;
    }

    private function sourceFactory(): SourceConfigurationFactory
    {
        $factory = SourceConfigurationFactory::create();
        $sources = [
            'sweep_base' => new SourceConfiguration([
                'name' => 'Sweep Base',
                'table' => self::BASE_COLLECTION,
                'shorthand' => self::SHORTHAND,
                'json_id' => 'id',
                'ingestion_service' => \Hiyori\Service\Ingestion\Source\AniListIngestion::class,
            ]),
            'sweep_candidate' => new SourceConfiguration([
                'name' => 'Sweep Candidate',
                'table' => self::CANDIDATE_COLLECTION,
                'shorthand' => self::OTHER_SHORTHAND,
                'json_id' => 'id',
                'ingestion_service' => \Hiyori\Service\Ingestion\Source\KitsuIngestion::class,
            ]),
        ];

        $property = new \ReflectionProperty(SourceConfigurationFactory::class, 'sources');
        $property->setValue($factory, $sources);

        return $factory->setCurrent('sweep_base');
    }
}
