<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Database;
use Hiyori\Service\Ml\LabelStore;
use Hiyori\Service\Ml\RejectionStore;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * The rejection buffer (`pair_rejections`) is the review queue that bridges
 * "the scorer said no" and "the trainer learns from it": a reviewer works the
 * queue and promote() copies decided pairs into `_labels`.
 */
final class RejectionStoreTest extends TestCase
{
    private const COLLECTION = 'pair_rejections_test';

    private const LABELS = '_labels_test';

    private Client $client;

    private RejectionStore $store;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
        $this->client->hiyori->selectCollection(self::LABELS)->drop();
        $this->store = RejectionStore::create(new Database(SerializerBuilder::create()->build()), self::COLLECTION);
        $this->store->ensureIndexes();
    }

    protected function tearDown(): void
    {
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
        $this->client->hiyori->selectCollection(self::LABELS)->drop();
    }

    public function testUpsertIsIdempotentPerPairAndUpdatesTheDecision(): void
    {
        $this->assertTrue($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => 'al',
            'candidate_id' => '2',
            'decision' => 'reject',
            'score' => 42.5,
        ]));
        $this->assertSame(1, $this->store->count());

        // Same pair, flipped decision — updates in place, no duplicate row.
        $this->assertTrue($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => 'al',
            'candidate_id' => '2',
            'decision' => 'approve',
            'notes' => 'same work after all',
        ]));
        $this->assertSame(1, $this->store->count());
        $this->assertSame(1, $this->store->count('approve'));
        $this->assertSame(0, $this->store->count('reject'));
    }

    public function testUpsertRejectsIncompletePairsAndNormalisesDecision(): void
    {
        $this->assertFalse($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => '',
            'candidate_id' => '2',
            'decision' => 'reject',
        ]));
        $this->assertSame(0, $this->store->count());

        // Unknown decision falls back to reject.
        $this->assertTrue($this->store->upsert([
            'base_source' => 'mal',
            'base_id' => '1',
            'candidate_source' => 'al',
            'candidate_id' => '2',
            'decision' => 'bogus',
        ]));
        $this->assertSame(1, $this->store->count('reject'));
    }

    public function testListAndStats(): void
    {
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '10', 'candidate_source' => 'al', 'candidate_id' => '20', 'decision' => 'reject']);
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '10', 'candidate_source' => 'k', 'candidate_id' => '30', 'decision' => 'unsure']);
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '11', 'candidate_source' => 'al', 'candidate_id' => '21', 'decision' => 'approve', 'source' => 'agent-review']);

        $rows = $this->store->list();
        $this->assertCount(3, $rows);
        // Newest first.
        $this->assertSame('al:21', (string) $rows[0]['candidate_source'] . ':' . (string) $rows[0]['candidate_id']);

        $stats = $this->store->stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['by_decision']['reject']);
        $this->assertSame(1, $stats['by_decision']['unsure']);
        $this->assertSame(1, $stats['by_decision']['approve']);
        $this->assertSame(2, $stats['by_source']['human'] ?? 0);
        $this->assertSame(1, $stats['by_source']['agent-review'] ?? 0);
        $this->assertSame(3, $stats['pending']);
        $this->assertSame(0, $stats['promoted']);
    }

    public function testPromoteWritesDecidedLabelsAndSkipsUnsure(): void
    {
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '1', 'candidate_source' => 'al', 'candidate_id' => '2', 'decision' => 'reject']);
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '3', 'candidate_source' => 'al', 'candidate_id' => '4', 'decision' => 'approve']);
        $this->store->upsert(['base_source' => 'mal', 'base_id' => '5', 'candidate_source' => 'al', 'candidate_id' => '6', 'decision' => 'unsure']);

        $result = $this->store->promote(null, null, self::LABELS);
        $this->assertSame(3, $result['promoted']);
        // approve + reject become labels; unsure is promoted without a label.
        $this->assertSame(2, $result['labels']);

        $labels = LabelStore::create($this->store->db(), self::LABELS);
        $this->assertSame(0, $labels->forBase('mal', '1')['al:2'] ?? null);
        $this->assertSame(1, $labels->forBase('mal', '3')['al:4'] ?? null);
        $this->assertArrayNotHasKey('al:6', $labels->forBase('mal', '5'));

        // Everything is now promoted; a second promote is a no-op.
        $this->assertSame(0, $this->store->count(null, false));
        $again = $this->store->promote(null, null, self::LABELS);
        $this->assertSame(0, $again['promoted']);
        $this->assertSame(0, $again['labels']);
    }

    /**
     * A pair whose metadata matches perfectly (type/episodes/status/season/year)
     * but whose titles are unrelated: the title floor vetoes the merge, yet the
     * metadata score still clears the threshold — the borderline false-negative
     * the rejection buffer exists to surface.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function borderlinePair(): array
    {
        $entry = [
            'title' => 'Cowboy Bebop', 'synonyms' => [], 'type' => 'TV', 'episodes' => 26,
            'status' => 'finished', 'season' => 'spring', 'year' => 1998, 'tags' => ['Action'],
            'reference_ids' => ['mal' => '1'],
        ];
        $candidate = [
            'title' => 'Naruto', 'synonyms' => [], 'type' => 'TV', 'episodes' => 26,
            'status' => 'finished', 'season' => 'spring', 'year' => 1998, 'tags' => ['Adventure'],
            'reference_ids' => ['al' => '2'],
        ];

        return [$entry, $candidate];
    }

    public function testMatchBuffersBorderlineTitleFloorRejections(): void
    {
        $store = RejectionStore::create($this->store->db(), self::COLLECTION);
        $store->ensureIndexes();
        [$entry, $candidate] = $this->borderlinePair();

        $result = MetadataFuzzyStrategy::match(
            $entry,
            [$candidate],
            MetadataFuzzyStrategy::DEFAULT_WEIGHTS,
            70.0,
            true,
            1,
            false,
            0.30,
            $store,
            70.0,
            'mal',
            'al',
        );

        $this->assertNull($result['best']);
        $this->assertSame(1, $result['rejected_title']);
        $this->assertSame(1, $result['buffered']);

        $rows = $store->list();
        $this->assertCount(1, $rows);
        $this->assertSame('combiner', $rows[0]['source']);
        $this->assertSame('reject', $rows[0]['decision']);
        $this->assertSame('mal', $rows[0]['base_source']);
        $this->assertSame('1', $rows[0]['base_id']);
        $this->assertSame('al', $rows[0]['candidate_source']);
        $this->assertSame('2', $rows[0]['candidate_id']);
        $this->assertGreaterThanOrEqual(70.0, (float) $rows[0]['score']);
    }

    public function testMatchWithoutBufferWritesNothing(): void
    {
        [$entry, $candidate] = $this->borderlinePair();

        $result = MetadataFuzzyStrategy::match(
            $entry,
            [$candidate],
            MetadataFuzzyStrategy::DEFAULT_WEIGHTS,
            70.0,
            true,
            1,
            false,
            0.30,
        );

        $this->assertSame(0, $result['buffered']);
        $this->assertSame(0, $this->store->count());
    }
}
