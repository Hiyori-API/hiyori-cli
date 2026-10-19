<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Helper;
use Hiyori\Service\Database;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

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

    /**
     * Optional Mongo cache backend (`embeddings`). When present the service
     * reads through and writes through to the collection (unique on
     * `(content_hash, model, model_version)`) instead of the JSON file, so the
     * cache is shared across processes and does not grow a single unbounded
     * file. The file cache remains the default when no database is injected.
     */
    private ?Database $mongo;

    private string $mongoCollection;

    /** @var resource|null the warm sidecar process */
    private $sidecarProcess = null;

    /** @var resource|null the sidecar's stdin */
    private $sidecarIn = null;

    /** @var resource|null the sidecar's stdout */
    private $sidecarOut = null;

    /** @var resource|null the sidecar's stderr */
    private $sidecarErr = null;

    /** The sidecar's stderr, kept so a broken sidecar is visible. */
    private string $sidecarError = '';

    public function __construct(
        private int $dimensions = self::DEFAULT_DIMENSIONS,
        ?string $command = null,
        ?string $cacheFile = null,
        ?string $model = null,
        ?string $modelVersion = null,
        ?Database $mongo = null,
        string $mongoCollection = 'embeddings',
    ) {
        $this->dimensions = max(16, $dimensions);
        $this->command = $command ?? (getenv('ML_EMBED_COMMAND') ?: null);
        $this->cacheFile = $cacheFile ?? dirname(__DIR__, 3) . '/storage/ml/embeddings.json';
        $this->model = $model ?? (getenv('ML_MODEL') ?: ($this->isExternal()
            ? 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'
            : 'local-ngram'));
        $this->modelVersion = $modelVersion ?? (getenv('ML_EMBED_VERSION') ?: ($this->isExternal() ? 'sidecar-1' : 'local-ngram-1'));
        $this->mongo = $mongo;
        $this->mongoCollection = $mongoCollection;
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

        // Read-through: the Mongo cache (when enabled) is checked before paying
        // for a sidecar call or a local hash.
        if ($this->mongo !== null) {
            $cached = $this->fetchFromMongo($text);
            if ($cached !== null) {
                $this->cache[$key] = $cached;

                return $cached;
            }
        }

        $vector = null;
        if ($this->isExternal()) {
            $vector = $this->embedWithCommand($text);
        }
        $vector ??= $this->embedLocal($text);

        $this->cache[$key] = $vector;
        $this->cacheDirty = true;

        if ($this->mongo !== null) {
            $this->storeInMongo($text, $vector);
        }

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
        $this->stopSidecar();
        if ($this->mongo !== null) {
            return; // write-through already persisted every vector
        }
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

    public function __destruct()
    {
        $this->stopSidecar();
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
     * Embed one text through the sidecar.
     *
     * The sidecar speaks one JSON object per line on stdin and one JSON array
     * per line on stdout, so it can stay alive across texts. It used to be
     * spawned per text, which paid interpreter start + torch import + model
     * load EVERY time: 12.74 s per title against the model's own 28 ms warm
     * latency, a 450x penalty that would have made embedding the corpus a
     * ~62 hour job. A warm process removes it.
     *
     * The sidecar's stderr is captured rather than discarded, so a sidecar that
     * fails is visible instead of silently falling back to the n-gram hasher.
     *
     * @return array<int, float>|null
     */
    private function embedWithCommand(string $text): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (!$this->sidecarIsRunning()) {
                if (!$this->startSidecar()) {
                    return null;
                }
            }

            $payload = json_encode(['text' => $text, 'model' => $this->model]);
            if ($payload === false) {
                return null;
            }
            $line = $payload . "\n";
            if (@fwrite($this->sidecarIn, $line) === false) {
                $this->stopSidecar();

                continue;
            }

            $stdout = @fgets($this->sidecarOut);
            if ($stdout === false) {
                // EOF: the sidecar died. Report why, and try once more.
                $this->captureSidecarError();
                $this->stopSidecar();

                continue;
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

        return null;
    }

    private function sidecarIsRunning(): bool
    {
        return is_resource($this->sidecarProcess)
            && is_resource($this->sidecarIn)
            && is_resource($this->sidecarOut);
    }

    private function startSidecar(): bool
    {
        if ($this->command === null || $this->command === '') {
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($this->command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], false);

        $this->sidecarProcess = $process;
        $this->sidecarIn = $pipes[0];
        $this->sidecarOut = $pipes[1];
        $this->sidecarErr = $pipes[2];

        return true;
    }

    private function stopSidecar(): void
    {
        if (is_resource($this->sidecarIn)) {
            @fclose($this->sidecarIn);
        }
        if (is_resource($this->sidecarOut)) {
            @fclose($this->sidecarOut);
        }
        if (is_resource($this->sidecarErr)) {
            @fclose($this->sidecarErr);
        }
        if (is_resource($this->sidecarProcess)) {
            @proc_terminate($this->sidecarProcess);
            @proc_close($this->sidecarProcess);
        }

        $this->sidecarProcess = null;
        $this->sidecarIn = null;
        $this->sidecarOut = null;
        $this->sidecarErr = null;
    }

    /**
     * Drain whatever the sidecar wrote to stderr.
     *
     * It used to be closed unread, so a sidecar that could not load torch was
     * indistinguishable from one that had nothing to say — and the service
     * fell back to the n-gram hasher without a word.
     */
    private function captureSidecarError(): void
    {
        if (!is_resource($this->sidecarErr)) {
            return;
        }
        $stderr = (string) @stream_get_contents($this->sidecarErr);
        if (trim($stderr) === '') {
            return;
        }
        $this->sidecarError = trim($stderr);
        error_log('embedding sidecar: ' . $this->sidecarError);
    }

    private function cacheKey(string $text): string
    {
        return hash('sha1', $this->model . "\0" . $this->modelVersion . "\0" . $text);
    }

    private function collection(): Collection
    {
        return $this->mongo->getCollection($this->mongoCollection);
    }

    /**
     * @return array<int, float>|null
     */
    private function fetchFromMongo(string $text): ?array
    {
        try {
            $doc = $this->collection()->findOne([
                'content_hash' => sha1($text),
                'model' => $this->model,
                'model_version' => $this->modelVersion,
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($doc)) {
            return null;
        }
        $vector = $doc['vector'] ?? null;
        if (!is_array($vector)) {
            return null;
        }

        return array_map('floatval', array_values($vector));
    }

    /**
     * @param array<int, float> $vector
     */
    private function storeInMongo(string $text, array $vector): void
    {
        try {
            $this->collection()->updateOne(
                [
                    'content_hash' => sha1($text),
                    'model' => $this->model,
                    'model_version' => $this->modelVersion,
                ],
                ['$set' => [
                    'vector' => $vector,
                    'text' => $text,
                    'dimensions' => count($vector),
                    'updated_at' => new UTCDateTime(),
                ]],
                ['upsert' => true],
            );
        } catch (\Throwable) {
            // a cache write failure must never break embedding
        }
    }

    private function loadCache(): void
    {
        if ($this->mongo !== null) {
            return; // read-through: no preload, vectors are fetched per key
        }
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
