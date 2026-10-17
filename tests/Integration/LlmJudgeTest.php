<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Ml\LlmJudge;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * The LLM judge is env-gated and its HTTP layer is injectable, so everything
 * except the live API call is tested here: config gating, caching, cap/budget
 * guards, response parsing, and spend tracking.
 */
final class LlmJudgeTest extends TestCase
{
    private const COLLECTION = 'llm_judgements_test';

    private Client $client;

    private Database $db;

    /** @var list<array{0: string, 1: array<string, string>, 2: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            (new \Symfony\Component\Dotenv\Dotenv())->load($envFile);
        }
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
        $this->db = new Database(SerializerBuilder::create()->build());
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        $this->client->hiyori->selectCollection(self::COLLECTION)->drop();
    }

    private function judge(array $response, int $maxPairs = 2000, float $budget = 5.00): LlmJudge
    {
        $http = function (string $url, array $headers, string $body) use ($response): string {
            $this->calls[] = [$url, $headers, $body];

            return json_encode($response);
        };

        return new LlmJudge($this->db, 'openai', 'gpt-4o-mini', 'test-key', $maxPairs, $budget, $http, self::COLLECTION);
    }

    private function entry(string $title): array
    {
        return ['title' => $title, 'type' => 'TV', 'episodes' => 12, 'year' => 2020, 'status' => 'finished'];
    }

    public function testReturnsNullWhenNotConfigured(): void
    {
        $judge = new LlmJudge($this->db, 'openai', 'gpt-4o-mini', '', 2000, 5.0, null, self::COLLECTION);
        $this->assertNull($judge->decide($this->entry('A'), $this->entry('B')));
        $this->assertSame(0, $judge->spend()['tokens']);
    }

    public function testParsesDecisionCachesAndTracksSpend(): void
    {
        $judge = $this->judge([
            'choices' => [['message' => ['content' => '{"match": true, "confidence": 88, "reason": "same work"}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

        $result = $judge->decide($this->entry('Cowboy Bebop'), $this->entry('Cowboy Bebop'));
        $this->assertSame(['match' => true, 'confidence' => 88, 'reason' => 'same work'], $result);

        // One API call, one cache document.
        $this->assertCount(1, $this->calls);
        $this->assertSame(1, $this->db->getCollection(self::COLLECTION)->countDocuments());

        $spend = $judge->spend();
        $this->assertSame(120, $spend['tokens']);
        $this->assertSame(1, $spend['judged']);
        // gpt-4o-mini: $0.15/1M in, $0.60/1M out -> 0.000015 + 0.000012 = 0.000027
        $this->assertEqualsWithDelta(0.000027, $spend['cost_usd'], 0.0000001);
    }

    public function testCacheHitIsFreeAndDoesNotCountAgainstCap(): void
    {
        $response = [
            'choices' => [['message' => ['content' => '{"match": false, "confidence": 12, "reason": "different"}']]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
        ];
        $judge = $this->judge($response, 1, 5.0);

        $a = $this->entry('A');
        $b = $this->entry('B');
        $first = $judge->decide($a, $b);
        $this->assertFalse($first['match']);
        $this->assertCount(1, $this->calls);

        // Second call with the same pair: cache hit, no new API call, cap untouched.
        $second = $judge->decide($a, $b);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->calls);
        $this->assertSame(0, $judge->spend()['skipped_budget']);
    }

    public function testCapAndBudgetGuardSkipRemainingPairs(): void
    {
        $response = [
            'choices' => [['message' => ['content' => '{"match": true, "confidence": 90, "reason": "ok"}']]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500],
        ];
        // Budget $0.0001: the first call (1000 in / 500 out on gpt-4o-mini) costs
        // ~0.00045, which already exceeds it, so the second pair is skipped.
        $judge = $this->judge($response, 2000, 0.0001);

        $this->assertNotNull($judge->decide($this->entry('A'), $this->entry('B')));
        // The first call already exceeded the budget; the second is skipped.
        $this->assertNull($judge->decide($this->entry('C'), $this->entry('D')));
        $this->assertSame(1, $judge->spend()['skipped_budget']);
    }

    public function testMaxPairsPerRunGuard(): void
    {
        $response = [
            'choices' => [['message' => ['content' => '{"match": true, "confidence": 90, "reason": "ok"}']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];
        $judge = $this->judge($response, 1, 5.0);
        $this->assertNotNull($judge->decide($this->entry('A'), $this->entry('B')));
        $this->assertNull($judge->decide($this->entry('C'), $this->entry('D')));
        $this->assertSame(1, $judge->spend()['skipped_budget']);
    }

    public function testStripsMarkdownFencesAndRejectsMalformedJson(): void
    {
        $fenced = $this->judge([
            'choices' => [['message' => ['content' => "```json\n{\"match\": true, \"confidence\": 77, \"reason\": \"ok\"}\n```"]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
        $result = $fenced->decide($this->entry('A'), $this->entry('B'));
        $this->assertTrue($result['match']);
        $this->assertSame(77, $result['confidence']);

        $malformed = $this->judge([
            'choices' => [['message' => ['content' => 'not json at all']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
        // A fresh pair: (A, B) was already cached by the fenced call above.
        $this->assertNull($malformed->decide($this->entry('C'), $this->entry('D')));
    }

    public function testPairHashIsolatesModel(): void
    {
        $response = [
            'choices' => [['message' => ['content' => '{"match": true, "confidence": 80, "reason": "ok"}']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];
        $a = $this->entry('A');
        $b = $this->entry('B');

        $m1 = $this->judge($response);
        $m1->decide($a, $b);
        $this->assertCount(1, $this->calls);

        // Same pair, different model -> different cache key -> new API call.
        $http2 = function (string $url, array $headers, string $body) use ($response): string {
            $this->calls[] = [$url, $headers, $body];

            return json_encode($response);
        };
        $m2 = new LlmJudge($this->db, 'openai', 'gpt-4o', 'test-key', 2000, 5.0, $http2, self::COLLECTION);
        $m2->decide($a, $b);
        $this->assertCount(2, $this->calls);
    }
}
