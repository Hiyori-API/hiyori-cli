<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Helper;

/**
 * Level-0, local-only embedding service for the semantic re-rank strategy.
 *
 * Two backends are supported:
 *
 * 1. `ML_EMBED_COMMAND` (or the constructor argument) — a local sidecar such
 *    as `services/ml/embed.py` running sentence-transformers. It receives one
 *    JSON object per line on stdin (`{"text": "..."}`) and must answer with a
 *    JSON array of floats per line on stdout.
 * 2. A deterministic, dependency-free character/word n-gram hasher used when
 *    no sidecar is configured or the sidecar fails.
 *
 * The fallback is intentionally cheap: it is not a semantic model, but it is
 * stable, offline and good enough to rerank candidate pairs that already
 * passed the metadata prefilter.
 *
 * Cache keys are namespaced by `(model, model_version, sha1(text))` and the
 * cache file records the model/version it was built with, so switching
 * `ML_MODEL` (or the sidecar) invalidates stale vectors instead of silently
 * mixing embeddings from two models.
 */
final class EmbeddingService
{
    private const DEFAULT_DIMENSIONS = 256;

    /** @var array<string, array<int, float>> */
    private array $cache = [];

    private bool $cacheDirty = false;

    private ?string $command;

    private string $cacheFile;

    private string $model;

    private string $modelVersion;

    public function __construct(
        private int $dimensions = self::DEFAULT_DIMENSIONS,
        ?string $command = null,
        ?string $cacheFile = null,
        ?string $model = null,
        ?string $modelVersion = null,
    ) {
        $this->dimensions = max(16, $dimensions);
        $this->command = $command ?? (getenv('ML_EMBED_COMMAND') ?: null);
        $this->cacheFile = $cacheFile ?? dirname(__DIR__, 3) . '/storage/state/ml-embeddings.json';
        $this->model = $model ?? (getenv('ML_MODEL') ?: ($this->isExternal()
            ? 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'
            : 'local-ngram'));
        $this->modelVersion = $modelVersion ?? (getenv('ML_EMBED_VERSION') ?: ($this->isExternal() ? 'sidecar-1' : 'local-ngram-1'));
        $this->loadCache();
    }

    public function model(): string
    {
        return $this->model;
    }

    public function modelVersion(): string
    {
        return $this->modelVersion;
    }

    /** Number of vectors currently held in memory (after cache load). */
    public function cachedCount(): int
    {
        return count($this->cache);
    }

    public function isExternal(): bool
    {
        return $this->command !== null && trim($this->command) !== '';
    }

    /**
     * Embed a title + synonyms into one average vector.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<int, float>
     */
    public function embedEntry(array $entry): array
    {
        $parts = [];
        $title = trim((string) ($entry['title'] ?? ''));
        if ($title !== '') {
            $parts[] = $title;
        }
        foreach ((array) ($entry['synonyms'] ?? []) as $synonym) {
            if (is_string($synonym) && trim($synonym) !== '') {
                $parts[] = $synonym;
            }
        }
        if ($parts === []) {
            return array_fill(0, $this->dimensions, 0.0);
        }

        $sum = array_fill(0, $this->dimensions, 0.0);
        foreach ($parts as $part) {
            $vector = $this->embed($part);
            foreach ($vector as $i => $value) {
                $sum[$i] += $value;
            }
        }

        return self::normalize($sum);
    }

    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $key = $this->cacheKey($text);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $vector = null;
        if ($this->isExternal()) {
            $vector = $this->embedWithCommand($text);
        }
        $vector ??= $this->embedLocal($text);

        $this->cache[$key] = $vector;
        $this->cacheDirty = true;

        return $vector;
    }

    /** @param array<int, float> $a @param array<int, float> $b */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $x = $a[$i] ?? 0.0;
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }

    public function flush(): void
    {
        if (!$this->cacheDirty) {
            return;
        }
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        @file_put_contents($this->cacheFile, json_encode([
            'model' => $this->model,
            'version' => $this->modelVersion,
            'vectors' => $this->cache,
        ]));
        $this->cacheDirty = false;
    }

    /**
     * @return array<int, float>
     */
    private function embedLocal(string $text): array
    {
        $normalized = Helper::normalizeTitle($text, false) . ' ' . Helper::normalizeTitle($text, true);
        $tokens = preg_split('/[^a-z0-9\x{0080}-\x{ffff}]+/u', mb_strtolower($normalized, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $vector = array_fill(0, $this->dimensions, 0.0);
        foreach ($tokens as $token) {
            $this->addFeature($vector, $token, 1.0);
            $length = mb_strlen($token, 'UTF-8');
            for ($i = 0; $i < $length; $i++) {
                $gram = mb_substr($token, $i, 3, 'UTF-8');
                if (mb_strlen($gram, 'UTF-8') === 3) {
                    $this->addFeature($vector, $gram, 0.35);
                }
            }
        }

        return self::normalize($vector);
    }

    /** @param array<int, float> $vector */
    private function addFeature(array &$vector, string $feature, float $weight): void
    {
        $hash = crc32($feature);
        $index = $hash % $this->dimensions;
        $sign = (($hash >> 16) & 1) === 1 ? -1.0 : 1.0;
        $vector[$index] += $weight * $sign;
    }

    /**
     * @return array<int, float>|null
     */
    private function embedWithCommand(string $text): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($this->command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fwrite($pipes[0], json_encode(['text' => $text, 'model' => $this->model]) . "\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $stdout === false) {
            return null;
        }
        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }
        if ($vector === []) {
            return null;
        }
        $this->dimensions = count($vector);

        return self::normalize($vector);
    }

    private function cacheKey(string $text): string
    {
        return hash('sha1', $this->model . "\0" . $this->modelVersion . "\0" . $text);
    }

    private function loadCache(): void
    {
        if (!is_file($this->cacheFile)) {
            return;
        }
        $decoded = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (!is_array($decoded) || !is_array($decoded['vectors'] ?? null)) {
            return; // legacy/unrelated cache format — recompute
        }
        $model = (string) ($decoded['model'] ?? '');
        $version = (string) ($decoded['version'] ?? '');
        if ($model !== $this->model || $version !== $this->modelVersion) {
            return; // different model/version — vectors are not comparable
        }
        $vectors = [];
        foreach ($decoded['vectors'] as $key => $vector) {
            if (!is_array($vector)) {
                continue;
            }
            // JSON turns 0.0 into int 0; re-cast so the in-memory type stays float.
            $vectors[(string) $key] = array_map('floatval', array_values($vector));
        }
        $this->cache = $vectors;
    }

    /** @param array<int, float> $vector @return array<int, float> */
    private static function normalize(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm <= 0.0) {
            return $vector;
        }
        $norm = sqrt($norm);
        foreach ($vector as $i => $value) {
            $vector[$i] = $value / $norm;
        }

        return $vector;
    }
}
