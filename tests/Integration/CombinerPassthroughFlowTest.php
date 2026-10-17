<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Models\SourceConfiguration;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end check of the `passthrough` branch: a base entry that matches
 * another source is merged (and carries `confidence`), a base entry that
 * matches nothing is still written as its own record — without `confidence`,
 * because it is not a merge. Dropping those records is what made a rebuild
 * lose coverage and orphan registry ids.
 *
 * Runs against throwaway collections on the configured MongoDB, like the other
 * integration tests, with both factories pointed at synthetic sources so no
 * real source collection is read.
 */
final class CombinerPassthroughFlowTest extends TestCase
{
    private const BASE = 'combiner_passthrough_base_test';

    private const CANDIDATE = 'combiner_passthrough_candidate_test';

    private const OUT = 'combiner_passthrough_out_test';

    private const REJECTIONS = 'pair_rejections_combiner_test';

    private static Client $client;

    private static Database $db;

    private static string $telemetryDir = '';

    public static function setUpBeforeClass(): void
    {
        // Running the strategy writes telemetry, and the live directory is what
        // the dashboard polls: without this, `phpunit` leaves "the latest
        // combine" pointing at a three-document test collection.
        self::$telemetryDir = sys_get_temp_dir() . '/hiyori-telemetry-' . getmypid();
        putenv('HIYORI_TELEMETRY_DIR=' . self::$telemetryDir);

        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }

        self::$client = new Client($_ENV['MONGODB_URL']);
        self::$db = new Database(SerializerBuilder::create()->build());
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::BASE, self::CANDIDATE, self::OUT, self::REJECTIONS] as $name) {
            self::$client->hiyori->selectCollection($name)->drop();
        }

        putenv('HIYORI_TELEMETRY_DIR');
        foreach (glob(self::$telemetryDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$telemetryDir);
    }

    /** @param array<string, mixed> $overrides */
    private static function entry(string $id, string $title, array $overrides = []): array
    {
        return array_merge([
            'title' => $title,
            'synonyms' => [$title],
            'type' => 'tv',
            'episodes' => 12,
            'status' => 'finished',
            'season' => 'spring',
            'year' => 2020,
            'images' => [],
            'references' => [],
            'reference_ids' => ['al' => $id],
            'tags' => ['action'],
        ], $overrides);
    }

    public function testMatchedEntriesMergeAndUnmatchedEntriesAreKept(): void
    {
        self::$client->hiyori->selectCollection(self::BASE)->drop();
        self::$client->hiyori->selectCollection(self::CANDIDATE)->drop();
        self::$client->hiyori->selectCollection(self::OUT)->drop();

        self::$client->hiyori->selectCollection(self::CANDIDATE)->insertOne(
            self::entry('900100', 'Passthrough Flow Match', ['reference_ids' => ['k' => '900100']]),
        );
        self::$client->hiyori->selectCollection(self::BASE)->insertMany([
            self::entry('900001', 'Passthrough Flow Match'),
            self::entry('900002', 'Passthrough Flow Lonely One'),
            self::entry('900003', 'Passthrough Flow Lonely Two'),
        ]);

        MetadataFuzzyStrategy::process(
            self::$db,
            ConsoleFactory::create(),
            StrategyConfigurationFactory::create()
                ->setStrategy(MetadataFuzzyStrategy::NAME)
                ->setConfig(['collection' => self::OUT, 'passthrough' => true]),
            self::sourceFactory(),
        );

        $out = self::$client->hiyori->selectCollection(self::OUT);
        $docs = $out->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();

        $this->assertCount(3, $docs, 'one document per base entry: merged or single-source');

        $byBase = [];
        foreach ($docs as $doc) {
            $byBase[(string) ($doc['reference_ids']['al'] ?? '')] = $doc;
        }
        $this->assertSame(['900001', '900002', '900003'], array_map('strval', array_keys($byBase)));

        // The entry that matched carries the merged reference set and a score.
        $this->assertSame(['k' => '900100', 'al' => '900001'], $byBase['900001']['reference_ids']);
        $this->assertArrayHasKey('confidence', $byBase['900001']);

        // The two that matched nothing are kept, with their own single reference
        // and no confidence (they are not merges).
        foreach (['900002', '900003'] as $id) {
            $this->assertSame(['al' => $id], $byBase[$id]['reference_ids'], 'single-source record keeps its own reference only');
            $this->assertArrayNotHasKey('confidence', $byBase[$id], 'a single-source record must not carry a merge score');
        }
        $this->assertSame('Passthrough Flow Lonely One', $byBase['900002']['title']);
    }

    public function testUnmatchedEntriesAreDroppedWithoutTheFlag(): void
    {
        self::$client->hiyori->selectCollection(self::BASE)->drop();
        self::$client->hiyori->selectCollection(self::CANDIDATE)->drop();
        self::$client->hiyori->selectCollection(self::OUT)->drop();

        self::$client->hiyori->selectCollection(self::CANDIDATE)->insertOne(
            self::entry('900100', 'Passthrough Flow Match', ['reference_ids' => ['k' => '900100']]),
        );
        self::$client->hiyori->selectCollection(self::BASE)->insertMany([
            self::entry('900001', 'Passthrough Flow Match'),
            self::entry('900002', 'Passthrough Flow Lonely One'),
        ]);

        MetadataFuzzyStrategy::process(
            self::$db,
            ConsoleFactory::create(),
            StrategyConfigurationFactory::create()
                ->setStrategy(MetadataFuzzyStrategy::NAME)
                ->setConfig(['collection' => self::OUT]),
            self::sourceFactory(),
        );

        $this->assertSame(
            1,
            self::$client->hiyori->selectCollection(self::OUT)->countDocuments(),
            'without passthrough only the matched entry is written (the legacy behaviour)',
        );
    }

    /**
     * A factory holding only the two synthetic sources, so the strategy never
     * reads a real source collection.
     */
    /**
     * `combine --rejection-buffer` writes borderline title-floor rejections to
     * `pair_rejections`: a candidate whose metadata matches perfectly (so the
     * weighted score clears the threshold) but whose title is unrelated, which
     * the title floor vetoes. Those are the pairs a reviewer most needs, and
     * with no store passed nothing is written.
     */
    public function testRejectionBufferCapturesBorderlineTitleFloorRejections(): void
    {
        self::$client->hiyori->selectCollection(self::BASE)->drop();
        self::$client->hiyori->selectCollection(self::CANDIDATE)->drop();
        self::$client->hiyori->selectCollection(self::OUT)->drop();
        self::$client->hiyori->selectCollection(self::REJECTIONS)->drop();

        // Same metadata, unrelated titles -> title floor vetoes, metadata score
        // is ~72 (well above the 70 buffer floor).
        self::$client->hiyori->selectCollection(self::CANDIDATE)->insertOne(
            self::entry('900200', 'Totally Unrelated Borderline Title', ['reference_ids' => ['k' => '900200']]),
        );
        self::$client->hiyori->selectCollection(self::BASE)->insertOne(
            self::entry('900201', 'A Completely Different Show', ['reference_ids' => ['al' => '900201']]),
        );

        MetadataFuzzyStrategy::process(
            self::$db,
            ConsoleFactory::create(),
            StrategyConfigurationFactory::create()
                ->setStrategy(MetadataFuzzyStrategy::NAME)
                ->setConfig([
                    'collection' => self::OUT,
                    'rejection_buffer' => true,
                    'rejection_buffer_floor' => 70.0,
                    'rejection_buffer_collection' => self::REJECTIONS,
                ]),
            self::sourceFactory(),
        );

        $rejections = self::$client->hiyori->selectCollection(self::REJECTIONS)
            ->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']])
            ->toArray();

        $this->assertCount(1, $rejections, 'one buffered borderline rejection');
        $this->assertSame('reject', $rejections[0]['decision']);
        $this->assertSame('combiner', $rejections[0]['source']);
        $this->assertSame('al', $rejections[0]['base_source']);
        $this->assertSame('900201', $rejections[0]['base_id']);
        $this->assertSame('k', $rejections[0]['candidate_source']);
        $this->assertSame('900200', $rejections[0]['candidate_id']);
        $this->assertGreaterThanOrEqual(70.0, (float) $rejections[0]['score']);

        // The buffered pair must not have been merged.
        $out = self::$client->hiyori->selectCollection(self::OUT)->countDocuments();
        $this->assertSame(0, $out, 'a title-floor rejection is still not a merge');
    }

    /**
     * Idempotency / content-hash regression: re-running the same combine over
     * unchanged inputs must reproduce the same documents — same merges, same
     * scores, same single-source records. A rebuild that is *not* idempotent is
     * what silently churns the id registry and flips merge decisions between
     * runs, so this pins the "same inputs -> same output" contract.
     */
    public function testRerunningTheSameCombineIsIdempotent(): void
    {
        self::$client->hiyori->selectCollection(self::BASE)->drop();
        self::$client->hiyori->selectCollection(self::CANDIDATE)->drop();
        self::$client->hiyori->selectCollection(self::OUT)->drop();

        self::$client->hiyori->selectCollection(self::CANDIDATE)->insertMany([
            self::entry('900300', 'Idempotent Match One', ['reference_ids' => ['k' => '900300']]),
            self::entry('900301', 'Idempotent Match Two', ['reference_ids' => ['k' => '900301']]),
        ]);
        self::$client->hiyori->selectCollection(self::BASE)->insertMany([
            self::entry('900302', 'Idempotent Match One'),
            self::entry('900303', 'Idempotent Match Two'),
            self::entry('900304', 'Idempotent Lonely One'),
        ]);

        $run = function (): array {
            self::$client->hiyori->selectCollection(self::OUT)->drop();
            MetadataFuzzyStrategy::process(
                self::$db,
                ConsoleFactory::create(),
                StrategyConfigurationFactory::create()
                    ->setStrategy(MetadataFuzzyStrategy::NAME)
                    ->setConfig(['collection' => self::OUT, 'passthrough' => true]),
                self::sourceFactory(),
            );

            $docs = self::$client->hiyori->selectCollection(self::OUT)
                ->find([], ['typeMap' => ['root' => 'array', 'document' => 'array']])
                ->toArray();

            // Sort by base reference so the comparison does not depend on order.
            $snapshot = [];
            foreach ($docs as $doc) {
                $base = (string) ($doc['reference_ids']['al'] ?? $doc['reference_ids']['k'] ?? '');
                $snapshot[$base] = [
                    'references' => array_map('strval', $doc['reference_ids'] ?? []),
                    'title' => (string) ($doc['title'] ?? ''),
                    'confidence' => isset($doc['confidence']) ? round((float) $doc['confidence'], 6) : null,
                    'hiyori_id' => $doc['hiyori_id'] ?? null,
                ];
            }
            ksort($snapshot);

            return $snapshot;
        };

        $first = $run();
        $second = $run();

        $this->assertCount(3, $first, 'one document per base entry on both runs');
        $this->assertSame($first, $second, 're-running the combine must reproduce the same documents');

        // Sanity: the run really merged and really kept the unmatched entry, so
        // the comparison above is not trivially true over two empty outputs.
        $this->assertSame(['k' => '900300', 'al' => '900302'], $first['900302']['references']);
        $this->assertNotNull($first['900302']['confidence']);
        $this->assertSame(['al' => '900304'], $first['900304']['references']);
        $this->assertNull($first['900304']['confidence']);
    }

    private static function sourceFactory(): SourceConfigurationFactory
    {
        $factory = SourceConfigurationFactory::create();
        $sources = [
            'anilist_smoke' => new SourceConfiguration([
                'name' => 'AniList Smoke',
                'table' => self::BASE,
                'shorthand' => 'al',
                'json_id' => 'id',
                'ingestion_service' => \Hiyori\Service\Ingestion\Source\AniListIngestion::class,
            ]),
            'kitsu_smoke' => new SourceConfiguration([
                'name' => 'Kitsu Smoke',
                'table' => self::CANDIDATE,
                'shorthand' => 'k',
                'json_id' => 'id',
                'ingestion_service' => \Hiyori\Service\Ingestion\Source\KitsuIngestion::class,
            ]),
        ];

        $property = new \ReflectionProperty(SourceConfigurationFactory::class, 'sources');
        $property->setValue($factory, $sources);

        return $factory->setCurrent('anilist_smoke');
    }
}
