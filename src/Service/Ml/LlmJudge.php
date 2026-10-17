<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Service\Database;
use MongoDB\BSON\UTCDateTime;

/**
 * Level-1 LLM-as-judge — a cheap tie-break for the ambiguous band only.
 *
 * The local scorer (metadata_fuzzy confidence, or metadata_ml probability) is
 * authoritative; the LLM is consulted only when that score is inconclusive
 * (the 70–85 band) and a human would otherwise have to eyeball the pair. It is
 * opt-in and env-gated: without `LLM_API_KEY` every call is a no-op, so the
 * pipeline is unchanged until a key is provided.
 *
 *   LLM_PROVIDER           openai (default) | anthropic
 *   LLM_MODEL              default gpt-4o-mini / claude-3-5-haiku-20241022
 *   LLM_API_KEY            required — no key, no calls
 *   LLM_MAX_PAIRS_PER_RUN  hard cap on API calls (default 2000)
 *   LLM_BUDGET_USD         hard cap on estimated spend (default 5.00)
 *
 * Judgements are cached in `llm_judgements` keyed by
 * `sha256(json_encode([a, b, model, prompt_version]))`, so re-runs over
 * unchanged data are free. Only titles + metadata are sent — no URLs, no user
 * data. Spend is tracked for telemetry (`llm_tokens`, `llm_cost_usd`).
 */
final class LlmJudge
{
    public const PROMPT_VERSION = 1;

    /** @var array<string, array{0: float, 1: float}> USD per 1M tokens [input, output] */
    private const PRICES = [
        'gpt-4o-mini' => [0.15, 0.60],
        'gpt-4o' => [2.50, 10.00],
        'claude-3-5-haiku-20241022' => [0.80, 4.00],
        'claude-3-haiku-20240307' => [0.25, 1.25],
    ];

    private const DEFAULT_PRICE = [1.00, 3.00];

    private int $tokens = 0;

    private float $costUsd = 0.0;

    private int $judged = 0;

    private int $skippedBudget = 0;

    /** @var callable(string $url, array $headers, string $body): ?string */
    private $http;

    public function __construct(
        private Database $db,
        private string $provider,
        private string $model,
        private string $apiKey,
        private int $maxPairsPerRun = 2000,
        private float $budgetUsd = 5.00,
        ?callable $http = null,
        private string $collection = 'llm_judgements',
    ) {
        $this->http = $http ?? static fn (string $url, array $headers, string $body): ?string => self::defaultHttp($url, $headers, $body);
    }

    /**
     * Build a judge from the environment. Returns null when no API key is
     * configured, so callers can treat the judge as optional.
     */
    public static function fromEnv(Database $db): ?self
    {
        $provider = strtolower((string) (getenv('LLM_PROVIDER') ?: 'openai'));
        $apiKey = trim((string) (getenv('LLM_API_KEY') ?: ''));
        if ($apiKey === '') {
            return null;
        }
        $model = (string) (getenv('LLM_MODEL') ?: ($provider === 'anthropic'
            ? 'claude-3-5-haiku-20241022'
            : 'gpt-4o-mini'));

        return new self(
            $db,
            $provider === 'anthropic' ? 'anthropic' : 'openai',
            $model,
            $apiKey,
            (int) (getenv('LLM_MAX_PAIRS_PER_RUN') ?: 2000),
            (float) (getenv('LLM_BUDGET_USD') ?: 5.00),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Adjudicate one candidate pair. Returns `{match, confidence, reason}`,
     * or null when the pair was skipped (unconfigured, over cap/budget, or the
     * provider returned nothing usable). Cache hits are free and do not count
     * against the caps.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array{match: bool, confidence: int, reason: string}|null
     */
    public function decide(array $a, array $b): ?array
    {
        if ($this->apiKey === '') {
            return null;
        }

        // Cache hits are free and never count against the cap/budget, so the
        // cache is consulted before the guards.
        $pairHash = $this->pairHash($a, $b);
        $cached = $this->fetchCached($pairHash);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->judged >= $this->maxPairsPerRun || $this->costUsd >= $this->budgetUsd) {
            $this->skippedBudget++;

            return null;
        }

        $response = $this->callProvider($this->buildPrompt($a, $b));
        if ($response === null) {
            return null;
        }

        $parsed = $this->parseDecision($response['content']);
        if ($parsed === null) {
            return null;
        }

        $this->tokens += $response['inputTokens'] + $response['outputTokens'];
        $this->costUsd += $this->estimateCost($response['inputTokens'], $response['outputTokens']);
        $this->judged++;
        $this->storeCached($pairHash, $parsed);

        return $parsed;
    }

    /**
     * @return array{tokens: int, cost_usd: float, judged: int, skipped_budget: int}
     */
    public function spend(): array
    {
        return [
            'tokens' => $this->tokens,
            'cost_usd' => round($this->costUsd, 6),
            'judged' => $this->judged,
            'skipped_budget' => $this->skippedBudget,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function pairHash(array $a, array $b): string
    {
        return hash('sha256', json_encode([
            $this->compact($a),
            $this->compact($b),
            $this->model,
            self::PROMPT_VERSION,
        ]));
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private function compact(array $e): array
    {
        return [
            'title' => (string) ($e['title'] ?? ''),
            'synonyms' => array_values(array_filter((array) ($e['synonyms'] ?? []), 'is_string')),
            'type' => (string) ($e['type'] ?? ''),
            'episodes' => $e['episodes'] ?? $e['chapters'] ?? null,
            'status' => (string) ($e['status'] ?? ''),
            'year' => $e['year'] ?? null,
            'season' => $e['season'] ?? null,
            'tags_canonical' => $e['tags_canonical'] ?? [],
            'source' => (string) ($e['source'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function buildPrompt(array $a, array $b): string
    {
        return "You are an anime/manga metadata matcher. Decide whether two entries describe the SAME work.\n"
            . 'Entry A: ' . json_encode($this->compact($a)) . "\n"
            . 'Entry B: ' . json_encode($this->compact($b)) . "\n"
            . 'Respond with STRICT JSON only, no prose: {"match": true|false, "confidence": 0-100, "reason": "<=120 chars"}';
    }

    /**
     * @return array{content: string, inputTokens: int, outputTokens: int}|null
     */
    private function callProvider(string $prompt): ?array
    {
        return $this->provider === 'anthropic'
            ? $this->callAnthropic($prompt)
            : $this->callOpenAi($prompt);
    }

    /**
     * @return array{content: string, inputTokens: int, outputTokens: int}|null
     */
    private function callOpenAi(string $prompt): ?array
    {
        $raw = ($this->http)(
            'https://api.openai.com/v1/chat/completions',
            ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            json_encode([
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0,
            ]),
        );
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return null;
        }

        return [
            'content' => $content,
            'inputTokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            'outputTokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
        ];
    }

    /**
     * @return array{content: string, inputTokens: int, outputTokens: int}|null
     */
    private function callAnthropic(string $prompt): ?array
    {
        $raw = ($this->http)(
            'https://api.anthropic.com/v1/messages',
            ['x-api-key: ' . $this->apiKey, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
            json_encode([
                'model' => $this->model,
                'max_tokens' => 256,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
        );
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $content = $decoded['content'][0]['text'] ?? null;
        if (!is_string($content)) {
            return null;
        }

        return [
            'content' => $content,
            'inputTokens' => (int) ($decoded['usage']['input_tokens'] ?? 0),
            'outputTokens' => (int) ($decoded['usage']['output_tokens'] ?? 0),
        ];
    }

    private function parseDecision(string $content): ?array
    {
        $text = trim($content);
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return null;
        }
        $match = $decoded['match'] ?? null;
        $confidence = $decoded['confidence'] ?? null;
        if (!is_bool($match) || !is_numeric($confidence)) {
            return null;
        }
        $confidence = max(0, min(100, (int) $confidence));

        return [
            'match' => $match,
            'confidence' => $confidence,
            'reason' => mb_substr((string) ($decoded['reason'] ?? ''), 0, 120),
        ];
    }

    /**
     * @return array{match: bool, confidence: int, reason: string}|null
     */
    private function fetchCached(string $pairHash): ?array
    {
        try {
            $doc = $this->db->getCollection($this->collection)->findOne(
                ['pair_hash' => $pairHash],
                ['typeMap' => ['root' => 'array', 'document' => 'array']],
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($doc)) {
            return null;
        }
        $decision = $doc['decision'] ?? null;
        if (!is_array($decision) || !isset($decision['match'], $decision['confidence'])) {
            return null;
        }

        return [
            'match' => (bool) $decision['match'],
            'confidence' => (int) $decision['confidence'],
            'reason' => (string) ($decision['reason'] ?? ''),
        ];
    }

    /**
     * @param array{match: bool, confidence: int, reason: string} $decision
     */
    private function storeCached(string $pairHash, array $decision): void
    {
        try {
            $this->db->getCollection($this->collection)->updateOne(
                ['pair_hash' => $pairHash],
                ['$set' => [
                    'pair_hash' => $pairHash,
                    'model' => $this->model,
                    'prompt_version' => self::PROMPT_VERSION,
                    'decision' => $decision,
                    'updated_at' => new UTCDateTime(),
                ]],
                ['upsert' => true],
            );
        } catch (\Throwable) {
            // a cache write failure is non-fatal
        }
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        [$in, $out] = self::PRICES[$this->model] ?? self::DEFAULT_PRICE;

        return ($inputTokens / 1_000_000) * $in + ($outputTokens / 1_000_000) * $out;
    }

    private static function defaultHttp(string $url, array $headers, string $body): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $context);

        return $result === false ? null : $result;
    }
}
