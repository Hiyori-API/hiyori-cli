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

    private static Client $client;

    private static Database $db;

    public static function setUpBeforeClass(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }

        self::$client = new Client($_ENV['MONGODB_URL']);
        self::$db = new Database(SerializerBuilder::create()->build());
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::BASE, self::CANDIDATE, self::OUT] as $name) {
            self::$client->hiyori->selectCollection($name)->drop();
        }
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
