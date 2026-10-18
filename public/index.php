<?php

declare(strict_types=1);

/**
 * Live dashboard front controller for `php -S` router mode.
 *
 * Run via: php hiyori dashboard:serve --port 8080
 * Alternatively: php -S 127.0.0.1:8080 public/index.php
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/export-helpers.php';

use Hiyori\Service\AlertNotifier;
use Hiyori\Service\Database;
use Hiyori\Service\Explorer;
use Hiyori\Service\HealthCheck;
use Hiyori\Service\MetricsCollector;
use Hiyori\Service\Ml\LabelStore;
use Hiyori\Service\Ml\RejectionStore;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\Telemetry;
use Hiyori\Service\Validator;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client as MongoClient;
use Symfony\Component\Dotenv\Dotenv;

if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/../.env')) {
    (new Dotenv())->load(__DIR__ . '/../.env');
}

$mongoUrl = $_ENV['MONGODB_URL'] ?? 'mongodb://localhost:27017';
$client = new MongoClient($mongoUrl);
$database = new Database(SerializerBuilder::create()->build());
$rateLimiter = new RateLimiter(
    (int) ($_ENV['RATE_LIMIT_MAX'] ?? 100),
    (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
);
$health = new HealthCheck($client, $rateLimiter);
$metrics = new MetricsCollector();
$telemetry = new Telemetry();
$validator = new Validator();

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * File-backed TTL cache for expensive derived reads (external health probes,
 * the coverage matrix). Memoised per request as well, so two consumers in one
 * request pay for it once. Cached values live under storage/state/ttl/ where
 * Telemetry::allState() (non-recursive glob) cannot pick them up.
 */
function ttlCache(string $name, int $ttlSeconds, callable $produce): array
{
    static $memo = [];
    if (array_key_exists($name, $memo)) {
        return $memo[$name];
    }

    $dir = dirname(__DIR__) . '/storage/state/ttl';
    $file = $dir . '/' . (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '.json';

    if (is_file($file) && time() - (int) @filemtime($file) < $ttlSeconds) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded)) {
            return $memo[$name] = $decoded;
        }
    }

    $value = $produce();
    if (!is_dir($dir)) {
        @mkdir($dir, 0o777, true);
    }
    if (false !== @file_put_contents($file . '.tmp', json_encode($value, JSON_UNESCAPED_SLASHES))) {
        @rename($file . '.tmp', $file);
    }

    return $memo[$name] = $value;
}

$token = $_ENV['DASHBOARD_TOKEN'] ?? '';
if ($token !== '' && ($_GET['token'] ?? '') !== $token && ($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? '') !== $token) {
    sendJson(['error' => 'unauthorized'], 403);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' || $path === '/index.html' || $path === '/spa') {
    $spaIndex = dirname(__DIR__) . '/web/dist/index.html';
    $html = is_file($spaIndex) ? $spaIndex : __DIR__ . '/dashboard.html';
    if (!file_exists($html)) {
        sendJson(['error' => 'dashboard html missing'], 500);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo file_get_contents($html);
    exit;
}

// Static files shipped in web/public (PWA assets, vendored brand marks).
//
// This was an allowlist of three paths, which meant anything else added there
// 404'd; the source logos are served from /brand/*.ico. The realpath check below
// is what keeps this safe now that the set is open rather than hardcoded.
$publicDir = realpath(dirname(__DIR__) . '/web/public');
if ($publicDir !== false && str_starts_with($path, '/')) {
    $candidate = realpath($publicDir . $path);
    // Reject anything that escapes the public dir via `..` or a symlink.
    if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $publicDir . DIRECTORY_SEPARATOR)) {
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $types = [
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'json' => 'application/manifest+json',
            'webmanifest' => 'application/manifest+json',
        ];
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
            header('Cache-Control: no-cache');
            readfile($candidate);
            exit;
        }
    }
}

// React build assets
if (str_starts_with($path, '/assets/') || str_starts_with($path, '/vite.svg')) {
    $file = dirname(__DIR__) . '/web/dist' . str_replace(['..', '//'], '', $path);
    if (is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = ['js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=3600');
        echo file_get_contents($file);
        exit;
    }
}

// Read-only review browser for the dry-run dump under output/ (gitignored).

/**
 * Resolve a request path inside a base directory, refusing anything that
 * escapes it.
 *
 * The `/output/` and `/storage/backups/` routes concatenated the raw request
 * path onto a directory and called readfile(), so `/output/../.env` served the
 * application's own .env and `/output/../../../../../etc/passwd` served the
 * system file. Neither required authentication. This resolves the real path and
 * requires it to live under the intended directory, which is what the
 * `/web/public` route already did correctly.
 *
 * @return string|null absolute path when it is a real file inside $baseDir
 */
function containedFile(string $relative, string $baseDir): ?string
{
    if (str_contains($relative, "\0")) {
        return null;
    }
    $base = realpath($baseDir);
    if ($base === false) {
        return null;
    }
    $candidate = realpath($baseDir . '/' . ltrim($relative, '/'));
    if ($candidate === false || !is_file($candidate)) {
        return null;
    }

    return str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) ? $candidate : null;
}

if ($path === '/output' || $path === '/output/') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset=utf-8><title>output/ — hiyori dry-run</title><body style="font:13px/1.5 ui-monospace,monospace;max-width:960px;margin:24px auto;padding:0 16px">';
    echo '<h2>output/ — dry-run dump</h2><ul>';
    foreach (glob(dirname(__DIR__) . '/output/*', GLOB_ONLYDIR) ?: [] as $d) {
        echo '<li><a href="/output/' . basename($d) . '/\">' . basename($d) . '/</a></li>';
    }
    echo '<li><a href="/output/hiyoridb/README.md\">hiyoridb/README.md</a> (start here)</li>';
    echo '<li><a href="/output/hiyoridb/CHANGELOG.md\">hiyoridb/CHANGELOG.md</a></li>';
    echo '<li><a href="/output/hiyoridb/manifest.json\">hiyoridb/manifest.json</a></li>';
    echo '<li><a href="/output/hiyoridb/export-config.json\">hiyoridb/export-config.json</a></li>';
    echo '</ul></body>';
    exit;
}
if (str_starts_with($path, '/output/')) {
    $file = containedFile(substr($path, strlen('/output/')), dirname(__DIR__) . '/output');
    if ($file !== null) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimes = ['json' => 'application/json', 'jsonl' => 'application/x-ndjson', 'csv' => 'text/csv', 'md' => 'text/markdown', 'sql' => 'text/plain', 'gz' => 'application/gzip'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'text/plain') . '; charset=utf-8');
        header('Cache-Control: no-store');
        readfile($file);
        exit;
    }
    sendJson(['error' => 'not found', 'path' => $path], 404);
}

// Static assets (vendored libs) — safe allowlist of extensions.
if (str_starts_with($path, '/vendor/')) {
    $file = containedFile(substr($path, strlen('/vendor/')), __DIR__ . '/vendor');
    if ($file !== null) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = ['js' => 'application/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=3600');
        echo file_get_contents($file);
        exit;
    }
}

if ($path === '/metrics') {
    header('Content-Type: ' . $metrics->getContentType());
    echo $metrics->render();
    exit;
}

function collectionCounts(MongoClient $client): array
{
    $out = [];
    $namespace = $client->selectDatabase('hiyori');
    foreach ($namespace->listCollections() as $collection) {
        $name = $collection->getName();
        $out[$name] = $namespace->selectCollection($name)->countDocuments();
    }

    return $out;
}

function dbStats(MongoClient $client, ?array $counts = null): array
{
    $db = $client->selectDatabase('hiyori');
    $stats = $db->command(['dbstats' => 1])->toArray()[0] ?? [];
    // Reuse the caller's counts when it already listed them — this endpoint and
    // /api/status would otherwise walk every collection twice.
    $counts ??= collectionCounts($client);
    $out = [
        'dataSize' => (int) ($stats['dataSize'] ?? 0),
        'indexSize' => (int) ($stats['indexSize'] ?? 0),
        'storageSize' => (int) ($stats['storageSize'] ?? 0),
        'collections' => $counts,
        'totalDocuments' => array_sum($counts),
    ];

    return $out;
}

/**
 * Effective rate limits. Dashboard overrides (storage/state/ui.json → limits)
 * win over the RATE_LIMIT_* env vars, and Bootstrap.php applies the same
 * precedence when an ingest process starts.
 *
 * @return array{max: int, window: int, source: string}
 */
function rateLimits(): array
{
    $max = (int) ($_ENV['RATE_LIMIT_MAX'] ?? 100);
    $window = (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60);
    $source = isset($_ENV['RATE_LIMIT_MAX']) || isset($_ENV['RATE_LIMIT_WINDOW']) ? 'env' : 'default';

    $override = uiSettings()['limits'] ?? null;
    if (is_array($override) && (int) ($override['max'] ?? 0) > 0 && (int) ($override['window'] ?? 0) > 0) {
        $max = (int) $override['max'];
        $window = (int) $override['window'];
        $source = 'override';
    }

    return ['max' => $max, 'window' => $window, 'source' => $source];
}

function backupInfo(): array
{
    $dir = dirname(__DIR__) . '/storage/backups';
    // Any .gz in the backups dir is restorable, not just the `hiyori-<ts>.gz`
    // ones this app writes — manual archives (e.g. pre-rebuild snapshots) are
    // valid backups too and must show up in the list.
    $files = glob($dir . '/*.gz') ?: [];
    $last = null;
    foreach ($files as $f) {
        $mtime = filemtime($f);
        if ($last === null || $mtime > $last['mtime']) {
            $last = ['file' => basename($f), 'mtime' => $mtime, 'size' => filesize($f)];
        }
    }

    return ['exists' => $last !== null, 'last' => $last, 'count' => count($files)];
}

function controls(string $source): array
{
    $dir = dirname(__DIR__) . '/storage/state';
    $file = $dir . '/control-' . $source . '.json';

    return ['paused' => is_file($file) ? (bool) (json_decode((string) file_get_contents($file), true)['paused'] ?? false) : false];
}

/**
 * Per-collection reference coverage.
 *
 * @param array<string, int>|null $totals Optional map of collection → doc count
 *                                        (from collectionCounts()) to avoid
 *                                        re-counting every collection.
 */
function coverage(MongoClient $client, ?array $totals = null): array
{
    $db = $client->selectDatabase('hiyori');
    $shorthands = ['al', 'mal', 'k', 'adb', 'ann', 'b', 'cr', 'fm', 'nf', 'sb', 't', 'ttv', 'tvt', 'tx', 'wpen', 'wpja', 'yt', 'ap'];
    $totals ??= collectionCounts($client);

    // A doc counts for shorthand <sh> when the *key* is present in its
    // reference_ids map — what `{reference_ids.<sh>: {$exists: true}}` means.
    //
    // Doing that in one pass instead of 22 countDocuments() per collection:
    // normalise reference_ids to a key/value list, unwind, then count distinct
    // (doc, key) pairs. The normalisation matters because some ingesters write
    // `reference_ids: []` (an empty array) rather than `{}` for "no refs", and a
    // dotted path through an array resolves to [] rather than 'missing', so a
    // bare $type check would wrongly count every such document.
    $keyPairs = [
        '$cond' => [
            ['$eq' => [['$type' => '$reference_ids'], 'object']],
            ['$objectToArray' => '$reference_ids'],
            ['$cond' => [
                ['$eq' => [['$type' => '$reference_ids'], 'array']],
                ['$reduce' => [
                    'input' => '$reference_ids',
                    'initialValue' => [],
                    'in' => ['$concatArrays' => ['$$value', ['$cond' => [['$eq' => [['$type' => '$$this'], 'object']], ['$objectToArray' => '$$this'], []]]]],
                ]],
                [],
            ]],
        ],
    ];
    $pipeline = [
        // $setUnion collapses duplicate keys within a document, so a doc that
        // lists the same reference twice is still counted once.
        ['$set' => ['_keys' => ['$setUnion' => [
            ['$map' => ['input' => $keyPairs, 'as' => 'p', 'in' => '$$p.k']],
            [],
        ]]]],
        ['$unwind' => ['path' => '$_keys', 'preserveNullAndEmptyArrays' => false]],
        ['$group' => ['_id' => '$_keys', 'n' => ['$sum' => 1]]],
    ];

    $out = [];
    foreach ($db->listCollections() as $col) {
        $name = $col->getName();
        $row = ['total' => (int) ($totals[$name] ?? 0)];
        foreach ($shorthands as $sh) {
            $row[$sh] = 0;
        }
        try {
            foreach ($db->selectCollection($name)->aggregate($pipeline) as $r) {
                $k = (string) $r['_id'];
                if (array_key_exists($k, $row)) {
                    $row[$k] = (int) $r['n'];
                }
            }
        } catch (\Throwable $e) {
            // Views and non-document collections report zeros instead of a 500.
        }
        // Missingness for the 3 main refs, derived from the same pass.
        foreach (['al', 'mal', 'k'] as $sh) {
            $row['missing_' . $sh] = max(0, $row['total'] - $row[$sh]);
        }
        $out[$name] = $row;
    }

    return $out;
}

/**
 * Resolve a merged record's reference shorthands to the titles each source
 * actually holds, so a reviewer can judge a merge without leaving the page.
 *
 * Only shorthands with a local source collection are resolved; an unknown one
 * (e.g. `t` for trakt) still comes back as a row with a null title, so the
 * reviewer sees that the record references a source the dashboard cannot show.
 *
 * @param array<string, mixed> $refs shorthand => source id
 *
 * @return list<array{shorthand: string, id: string, title: ?string, year: ?int, type: ?string}>
 */
function sampleSourceTitles(MongoClient $client, array $refs): array
{
    static $tables = null;
    if ($tables === null) {
        $tables = [];
        /** @var array<string, array{table: string, shorthand: string}> $configs */
        $configs = require dirname(__DIR__) . '/src/Config/Sources.php';
        foreach ($configs as $config) {
            $tables[$config['shorthand']] = $config['table'];
        }
    }

    $db = $client->selectDatabase('hiyori');
    $rows = [];
    foreach ($refs as $shorthand => $id) {
        $shorthand = (string) $shorthand;
        $id = is_scalar($id) ? (string) $id : '';
        $row = ['shorthand' => $shorthand, 'id' => $id, 'title' => null, 'year' => null, 'type' => null];

        $table = $tables[$shorthand] ?? null;
        if ($table !== null && $id !== '') {
            $doc = $db->selectCollection($table)->findOne(
                ['reference_ids.' . $shorthand => $id],
                ['projection' => ['title' => 1, 'year' => 1, 'type' => 1]],
            );
            if ($doc !== null) {
                $doc = json_decode(json_encode($doc), true);
                $title = $doc['title'] ?? null;
                if (is_array($title)) {
                    $title = $title['romaji'] ?? $title['english'] ?? reset($title);
                }
                $row['title'] = is_scalar($title) ? (string) $title : null;
                $row['year'] = isset($doc['year']) ? (int) $doc['year'] : null;
                $row['type'] = isset($doc['type']) && is_scalar($doc['type']) ? (string) $doc['type'] : null;
            }
        }
        $rows[] = $row;
    }

    return $rows;
}

function readBenchmarkLog(string $file): array
{
    $rows = [];
    $walls = [];
    if (!is_file($file)) {
        return $rows;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('/^wall(?:_[a-z_]+)?:\s*([\d.]+)s/', $line, $m)) {
            $walls[] = (float) $m[1];
        }
        if (!str_starts_with($line, '{')) {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['strategy'])) {
            $decoded['wall'] = array_shift($walls) ?? 0.0;
            $rows[] = $decoded;
        }
    }

    return $rows;
}

function benchmarkData(Telemetry $telemetry): array
{
    $file = dirname(__DIR__) . '/storage/logs/strategy-benchmark-v2.log';

    // Only rows a benchmark run actually wrote are returned. This used to merge
    // a hardcoded seed table over the log, which fabricated `merges` for every
    // strategy (the log stores `total`, so the seeded values always won) — the
    // dashboard showed numbers no run had produced. `wall` is the "wall: 123s"
    // marker the benchmark script prints just before each strategy's JSON row.
    $out = [];
    foreach (readBenchmarkLog($file) as $row) {
        $out[] = [
            'strategy' => (string) ($row['strategy'] ?? ''),
            'wall_s' => (int) round((float) ($row['wall'] ?? 0)),
            'merges' => (int) ($row['total'] ?? 0),
            'dups' => (int) ($row['duplicate_keys'] ?? 0),
        ];
    }

    return [
        'recorded' => $out,
        'source' => $out === [] ? null : 'storage/logs/' . basename($file),
        'recorded_at' => $out === [] ? null : date('c', (int) filemtime($file)),
        'latest' => $telemetry->read('combine-latest'),
        'ml' => mlBenchmarkReport(),
    ];
}

/**
 * Read the latest offline ML benchmark report (`combine:benchmark`), if any.
 *
 * Exposes the threshold suggestion (best F1 row: threshold, predicted merges,
 * precision/recall/F1) and the shipping gate so the dashboard can show a
 * recommended threshold without re-running the sweep.
 */
function mlBenchmarkReport(): ?array
{
    $path = dirname(__DIR__) . '/storage/state/combine-benchmark.json';
    if (!is_file($path)) {
        return null;
    }
    $report = json_decode((string) @file_get_contents($path), true);
    if (!is_array($report)) {
        return null;
    }

    $best = [];
    foreach (($report['scorers'] ?? []) as $name => $scorer) {
        if (is_array($scorer) && isset($scorer['best']) && is_array($scorer['best'])) {
            $best[(string) $name] = $scorer['best'];
        }
    }

    return [
        'created_at' => $report['created_at'] ?? null,
        'input' => $report['input'] ?? null,
        'rows' => $report['rows'] ?? null,
        'ml_model_version' => $report['ml_model_version'] ?? null,
        'gate' => $report['gate'] ?? null,
        'best' => $best,
    ];
}

function outageDebounce(Telemetry $telemetry, bool $unreachable): bool
{
    // Only raise the "unreachable" alert after a couple of consecutive failed
    // probes and only while the outage is recent — a single rate-limit blip
    // (429) or one poll timeout must not flash the banner.
    $state = $telemetry->read('api-outage');
    $count = (int) ($state['count'] ?? 0);
    if ($unreachable) {
        $count++;
        $telemetry->update('api-outage', ['count' => $count, 'last_failed' => time()]);
    } else {
        $prev = (int) ($state['degraded_count'] ?? 0);
        $deg = $prev >= 3 ? 0 : $prev;
        $telemetry->update('api-outage', [
            'count' => 0,
            'last_failed' => null,
            'degraded_count' => max(0, $count === 0 ? $deg : $deg),
        ]);
    }

    return $count >= 2 && (time() - (int) ($state['last_failed'] ?? 0)) < 120;
}

/**
 * @param array<string, mixed> $health Result of HealthCheck::check(). Passed in
 *                                     rather than probed here so /api/status
 *                                     runs the external health check once.
 */
function alerts(array $health, Telemetry $telemetry): array
{
    $out = [];
    $h = $health;
    $rl = $h['checks']['rate_limiter']['limiters'] ?? [];
    foreach ($rl as $src => $s) {
        if (($s['circuit_state'] ?? 'closed') === 'open') {
            $out[] = ['level' => 'critical', 'type' => 'circuit_open', 'source' => $src, 'msg' => "Circuit breaker open for $src"];
        }
        if ((int) ($s['tokens_available'] ?? 999) < 5) {
            $out[] = ['level' => 'warn', 'type' => 'low_tokens', 'source' => $src, 'msg' => "Rate limit nearly exhausted for $src"];
        }
    }

    // "Unreachable" now means the probe got no HTTP response at all (transport
    // error / 5s timeout). 429 rate-limits are reachable and ignored here.
    $apis = $h['checks']['external_apis'] ?? [];
    $reachable = $apis['all_reachable'] ?? ($apis['healthy'] ?? true);
    if (!$reachable && outageDebounce($telemetry, true)) {
        $out[] = ['level' => 'critical', 'type' => 'api_down', 'source' => '*', 'msg' => 'One or more external APIs unreachable'];
    } elseif ($reachable) {
        outageDebounce($telemetry, false);
    }

    // Degraded (429 etc.) feedback — soft warning, only when sustained.
    if (($apis['healthy'] ?? true) === false && ($apis['all_reachable'] ?? true)) {
        $hits = [];
        foreach ($apis['endpoints'] ?? [] as $name => $ep) {
            if (($ep['healthy'] ?? true) === false) {
                $hits[] = $name . ($ep['note'] === 'rate_limited' ? ' (429)' : '');
            }
        }
        if ($hits !== []) {
            $st = $telemetry->read('api-outage');
            $dec = (int) ($st['degraded_count'] ?? 0) + 1;
            $telemetry->update('api-outage', array_merge($st, ['degraded_count' => $dec]));
            if ($dec >= 3) {
                $out[] = ['level' => 'warn', 'type' => 'api_degraded', 'source' => '*', 'msg' => 'External APIs degraded: ' . implode(', ', $hits)];
            }
        }
    }

    // Multi-channel delivery (webhook / telegram / e-mail), throttled once per
    // hour per channel+alert identity in the alerts-sent telemetry store.
    if ($out !== []) {
        AlertNotifier::deliver($out, $telemetry);
    }

    return $out;
}

function scheduleView(Telemetry $telemetry): array
{
    $s = $telemetry->read('schedule');
    $envSources = isset($_ENV['SCHEDULE_SOURCES']) && $_ENV['SCHEDULE_SOURCES'] !== '' ? $_ENV['SCHEDULE_SOURCES'] : 'anilist,kitsu,myanimelist';
    $sources = $s['sources'] ?? $envSources;
    $envInterval = isset($_ENV['SCHEDULE_INTERVAL']) && $_ENV['SCHEDULE_INTERVAL'] !== '' ? (int) $_ENV['SCHEDULE_INTERVAL'] : 86400;
    $interval = (int) ($s['interval'] ?? $envInterval);
    $last = (int) ($s['last_run'] ?? 0);

    return [
        'sources' => is_array($sources) ? implode(',', $sources) : (string) $sources,
        'interval_hours' => $interval / 3600,
        'next_in' => $last ? max(0, $last + $interval - time()) : null,
        'method' => $s['method'] ?? 'systemd-timer (see schedule --install)',
    ];
}

// ===== Export / publish API (per-entry JSON + Hiyori IDs) =====
function exportStateFile(): string
{
    $class = 'Hiyori\\Service\\ExportConfig';
    if (class_exists($class) && method_exists($class, 'statePath')) {
        try {
            $path = $class::statePath();
            if (is_string($path) && $path !== '') {
                return $path;
            }
        } catch (\Throwable $e) {
            // fall back to the conventional path
        }
    }

    return dirname(__DIR__) . '/storage/state/export.json';
}

/** @return array<string, mixed>|null */
function exportReadJsonFile(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Bootstrap export template (`hiyori.export.json`). Prefers the tracked path
 * published by `ExportConfig`, probing the conventional locations otherwise.
 *
 * @return array<string, mixed>|null
 */
function exportTemplate(): ?array
{
    $candidates = [];
    $class = 'Hiyori\\Service\\ExportConfig';
    if (class_exists($class) && method_exists($class, 'trackedPath')) {
        try {
            $path = $class::trackedPath();
            if (is_string($path) && $path !== '') {
                $candidates[] = $path;
            }
        } catch (\Throwable $e) {
            // fall back to the conventional paths
        }
    }
    $candidates[] = dirname(__DIR__) . '/hiyori.export.json';
    $candidates[] = dirname(__DIR__) . '/config/hiyori.export.json';

    foreach (array_unique($candidates) as $file) {
        $value = exportReadJsonFile($file);
        if ($value !== null) {
            return $value;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function exportState(): array
{
    return exportReadJsonFile(exportStateFile()) ?? [];
}

/** @param array<string, mixed> $state */
function exportSaveState(array $state): void
{
    $dir = dirname(exportStateFile());
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents(
        exportStateFile(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );
}

/**
 * Effective export config: `ExportConfig::load()` when the service is present,
 * otherwise template + state merged by hand, then any per-request override on
 * top. Never throws — a missing service degrades to the file-based config.
 *
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
function exportEffectiveConfig(array $override = []): array
{
    $config = [];
    $class = 'Hiyori\\Service\\ExportConfig';
    if (class_exists($class) && method_exists($class, 'load')) {
        try {
            $loaded = $class::load(exportStateFile());
            if (is_array($loaded)) {
                $config = $loaded;
            }
        } catch (\Throwable $e) {
            $config = [];
        }
    }
    if ($config === []) {
        $config = hiyoriExportMergeDeep(exportTemplate() ?? [], exportState());
    }
    if ($override !== []) {
        $config = hiyoriExportMergeDeep($config, $override);
    }

    return $config;
}

/**
 * Effective config shaped for the API/UI: canonical keys plus a
 * `filename_pattern` mirror (the Publish page reads that alias).
 *
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
function exportApiConfig(array $override = []): array
{
    $config = exportEffectiveConfig($override);
    if (!array_key_exists('filename_pattern', $config) && isset($config['filename']) && is_string($config['filename'])) {
        $config['filename_pattern'] = $config['filename'];
    }

    return $config;
}

/**
 * Build a per-request config override from `?config=<json>` plus dotted query
 * keys such as `include.tags=1` or `entities.manga=0`.
 *
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function exportQueryOverrides(array $query): array
{
    $override = [];

    if (isset($query['config']) && is_string($query['config']) && trim($query['config']) !== '') {
        $decoded = json_decode($query['config'], true);
        if (!is_array($decoded)) {
            sendJson(['error' => 'config query parameter must be a JSON object'], 400);
        }
        $override = hiyoriExportMergeDeep($override, $decoded);
    }

    $reserved = ['token' => true, 'config' => true, 'kind' => true];
    foreach ($query as $key => $value) {
        if (!is_string($key) || isset($reserved[$key]) || !str_contains($key, '.')) {
            continue;
        }
        exportSetNested($override, $key, exportCoerceQueryValue($value));
    }

    return $override;
}

/** @param array<string, mixed> $target */
function exportSetNested(array &$target, string $path, mixed $value): void
{
    $parts = array_values(array_filter(explode('.', $path), static fn (string $part): bool => $part !== ''));
    if ($parts === []) {
        return;
    }
    $cursor = &$target;
    $last = count($parts) - 1;
    foreach ($parts as $index => $part) {
        if ($index === $last) {
            $cursor[$part] = $value;

            return;
        }
        if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
            $cursor[$part] = [];
        }
        $cursor = &$cursor[$part];
    }
}

function exportCoerceQueryValue(mixed $value): mixed
{
    if (!is_string($value)) {
        return $value;
    }
    $lower = strtolower($value);
    if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($lower, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    if (is_numeric($value)) {
        return $value + 0;
    }

    return $value;
}

/** Accept the Publish UI's `filename_pattern` alias for the canonical `filename`. */
function exportNormalizeAliases(array $config): array
{
    if (!array_key_exists('filename', $config) && isset($config['filename_pattern'])) {
        $config['filename'] = $config['filename_pattern'];
    }

    return $config;
}

function exportRequireService(): void
{
    if (!class_exists('Hiyori\\Service\\Exporter')) {
        sendJson([
            'error' => 'exporter unavailable',
            'detail' => 'Hiyori\\Service\\Exporter is not installed; the export endpoints are disabled',
        ], 503);
    }
}

function exportCombineRunning(): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $out = [];
    $code = 1;
    // The `[p]hp` bracket trick stops pgrep matching its own command line.
    @exec('pgrep -f ' . escapeshellarg('[p]hp hiyori combine') . ' 2>/dev/null', $out, $code);

    return $code === 0 && $out !== [];
}

/** @param array<string, mixed> $written */
function exportFileCount(mixed $files, array $written): int
{
    if (is_int($files)) {
        return $files;
    }
    if (is_array($files)) {
        return count($files);
    }

    return count($written);
}

if ($path === '/api/export-config') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJsonBody();

        if (($body['reset'] ?? false) === true) {
            @unlink(exportStateFile());
            auditAppend('export.config', ['reset' => true]);
            sendJson(['reset' => true, 'config' => exportApiConfig(), 'state' => []]);
        }

        $incoming = $body['config'] ?? null;
        if (!is_array($incoming)) {
            $incoming = array_diff_key($body, ['reset' => true]);
        }
        $incoming = exportNormalizeAliases($incoming);
        if ($incoming === []) {
            sendJson(['error' => 'config object required'], 400);
        }

        $errors = hiyoriExportConfigErrors($incoming, hiyoriExportProfiles());
        if ($errors !== []) {
            sendJson(['error' => 'invalid export config', 'errors' => $errors], 400);
        }

        $state = hiyoriExportMergeDeep(exportState(), $incoming);
        exportSaveState($state);
        auditAppend('export.config', ['keys' => array_keys($incoming)]);

        sendJson(['config' => exportApiConfig(), 'state' => $state]);
    }

    sendJson([
        'config' => exportApiConfig(),
        'template' => exportTemplate(),
        'state' => exportState(),
        'timestamp' => date('c'),
    ]);
}

if ($path === '/api/export/preview') {
    exportRequireService();
    $kind = (string) ($_GET['kind'] ?? 'anime');
    if (!in_array($kind, ['anime', 'manga'], true)) {
        sendJson(['error' => 'kind must be anime or manga'], 400);
    }
    $config = exportEffectiveConfig(exportQueryOverrides($_GET));
    try {
        $result = (new \Hiyori\Service\Exporter($client))->preview($config, $kind);
    } catch (\Throwable $e) {
        sendJson(['error' => 'export preview failed', 'detail' => $e->getMessage()], 500);
    }
    $out = is_array($result) ? $result : [];
    $out['entry'] ??= null;
    $out['rendered'] ??= $out['json'] ?? null;
    $out['kind'] ??= $kind;
    if (!isset($out['profile']) && isset($config['profile'])) {
        $out['profile'] = $config['profile'];
    }

    sendJson($out);
}

if ($path === '/api/export/dry-run') {
    exportRequireService();
    $config = exportEffectiveConfig(exportQueryOverrides($_GET));
    try {
        $result = (new \Hiyori\Service\Exporter($client))->dryRun($config);
    } catch (\Throwable $e) {
        sendJson(['error' => 'export dry-run failed', 'detail' => $e->getMessage()], 500);
    }
    $out = is_array($result) ? $result : [];
    $out += ['files' => 0, 'byKind' => [], 'sampleFilenames' => [], 'bytesEstimate' => 0];
    $byKind = is_array($out['byKind']) ? $out['byKind'] : [];
    $samples = is_array($out['sampleFilenames']) ? $out['sampleFilenames'] : [];
    $out += [
        'by_kind' => $byKind,
        'counts' => $byKind,
        'samples' => $samples,
        'sample_files' => $samples,
        'bytes' => $out['bytesEstimate'],
        'size' => $out['bytesEstimate'],
    ];

    sendJson($out);
}

if ($path === '/api/export/run') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['error' => 'POST required'], 405);
    }
    exportRequireService();
    if (exportCombineRunning()) {
        sendJson([
            'error' => 'combine in progress',
            'detail' => 'refusing to write a publish dump while `php hiyori combine` is running',
        ], 409);
    }

    $body = readJsonBody();
    $override = $body['config'] ?? array_diff_key($body, ['reset' => true]);
    $override = is_array($override) ? exportNormalizeAliases($override) : [];
    if ($override !== []) {
        $errors = hiyoriExportConfigErrors($override, hiyoriExportProfiles());
        if ($errors !== []) {
            sendJson(['error' => 'invalid export config', 'errors' => $errors], 400);
        }
    }

    $config = exportEffectiveConfig($override);
    try {
        $result = (new \Hiyori\Service\Exporter($client))->write($config);
    } catch (\Throwable $e) {
        auditAppend('export.run', ['ok' => false, 'error' => $e->getMessage()]);
        sendJson(['error' => 'export run failed', 'detail' => $e->getMessage()], 500);
    }

    $out = is_array($result) ? $result : [];
    $written = is_array($out['written'] ?? null) ? $out['written'] : [];
    $changelog = is_array($out['changelog'] ?? null) ? $out['changelog'] : [];
    auditAppend('export.run', [
        'dir' => $out['dir'] ?? null,
        'files' => exportFileCount($out['files'] ?? null, $written),
        'rows' => $out['rows'] ?? null,
    ]);

    $out += ['dir' => null, 'written' => [], 'files' => 0, 'rows' => 0, 'changelog' => null];
    $out += ['deltas' => [
        'added' => (int) ($changelog['added'] ?? 0),
        'changed' => (int) ($changelog['updated'] ?? 0),
        'removed' => (int) ($changelog['removed'] ?? 0),
    ]];

    sendJson($out);
}

// Lookup a single entry by its stable global hiyori_id (organized + manga).
if (preg_match('#^/api/(entries|anime|manga)/(\d+)$#', $path, $m) === 1) {
    $scope = $m[1];
    $id = (int) $m[2];
    $targets = match ($scope) {
        'anime' => ['anime' => 'organized'],
        'manga' => ['manga' => 'organized_manga'],
        default => ['anime' => 'organized', 'manga' => 'organized_manga'],
    };
    $db = $client->selectDatabase('hiyori');
    $entry = null;
    $kind = null;
    foreach ($targets as $candidateKind => $collection) {
        try {
            $doc = $db->selectCollection($collection)->findOne(['hiyori_id' => $id]);
        } catch (\Throwable $e) {
            $doc = null;
        }
        if ($doc !== null) {
            $entry = json_decode(json_encode($doc), true);
            $kind = $candidateKind;
            break;
        }
    }
    if ($entry === null) {
        sendJson(['error' => 'entry not found', 'hiyori_id' => $id], 404);
    }
    sendJson(['entry' => $entry, 'kind' => $kind]);
}

if ($path === '/api/counts') {
    sendJson(['counts' => collectionCounts($client), 'timestamp' => date('c')]);
}

if ($path === '/api/jobs') {
    sendJson(['jobs' => $telemetry->allState(), 'timestamp' => date('c')]);
}

if ($path === '/api/health') {
    sendJson(['health' => $health->check(), 'timestamp' => date('c')]);
}

if ($path === '/api/validate') {
    sendJson(['recent' => $telemetry->tail('validation-log', 100), 'timestamp' => date('c')]);
}

if ($path === '/api/failures') {
    // Validation-log entries, newest first. The UI groups these by source+title
    // (Management) and lists them with a source filter (Logs), so return the
    // full parsed event (ts/source/title/errors) rather than a count. `total`
    // lets the UI say "newest 500 of N" instead of implying this is the lot.
    $meta = tailLinesMeta(dirname(__DIR__) . '/storage/state/validation-log.jsonl', 500);
    sendJson(['failures' => array_reverse($meta['events']), 'total' => $meta['total'], 'timestamp' => date('c')]);
}

if ($path === '/api/audit') {
    $meta = tailLinesMeta(dirname(__DIR__) . '/storage/state/audit.jsonl', 200);
    sendJson(['audit' => array_reverse($meta['events']), 'total' => $meta['total'], 'timestamp' => date('c')]);
}

if ($path === '/api/history') {
    // Latest run state per ingest source. State files are last-write-wins, so
    // there is one entry per source rather than a multi-run log; `runs` stays
    // empty until per-run records exist.
    $states = [];
    foreach ($telemetry->allState() as $key => $value) {
        if (str_starts_with($key, 'ingest-')) {
            $states[$key] = $value;
        }
    }
    sendJson(['states' => $states, 'runs' => [], 'timestamp' => date('c')]);
}

if ($path === '/api/limits') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJsonBody();
        $max = (int) ($body['max'] ?? 0);
        $window = (int) ($body['window'] ?? 0);
        if ($max < 1 || $max > 100000 || $window < 1 || $window > 86400) {
            sendJson(['error' => 'max must be 1..100000 and window 1..86400 seconds'], 400);
        }
        $settings = uiSettings();
        $settings['limits'] = ['max' => $max, 'window' => $window, 'updated_at' => date('c')];
        saveUiSettings($settings);
        auditAppend('limits.update', ['max' => $max, 'window' => $window]);
        sendJson(['limits' => rateLimits(), 'applied' => 'next ingest start']);
    }
    sendJson(['limits' => rateLimits(), 'applied' => 'next ingest start']);
}

if ($path === '/api/backups') {
    sendJson(['backups' => backupsList(), 'timestamp' => date('c')]);
}

if ($path === '/api/restore') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['error' => 'POST required'], 405);
    }
    $body = readJsonBody();
    $file = basename((string) ($body['file'] ?? ''));
    $archive = dirname(__DIR__) . '/storage/backups/' . $file;
    // Only archives that the dashboard itself lists may be restored, so a typo
    // or a crafted path can never point restore at an arbitrary file.
    if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.gz$/', $file) || !is_file($archive)) {
        sendJson(['error' => 'unknown backup archive'], 400);
    }
    $exe = sprintf(
        'cd %s && php hiyori restore %s --drop >/dev/null 2>&1 &',
        escapeshellarg(dirname(__DIR__)),
        escapeshellarg($archive),
    );
    @exec($exe);
    auditAppend('restore', ['file' => $file]);
    sendJson(['started' => true, 'file' => $file]);
}

if ($path === '/api/db') {
    sendJson(['db' => dbStats($client), 'timestamp' => date('c')]);
}

if ($path === '/api/backup') {
    if (($_GET['run'] ?? '') === '1') {
        $out = dirname(__DIR__) . '/storage/backups/hiyori-' . date('Ymd-His') . '.gz';
        if (!is_dir(dirname($out))) {
            mkdir(dirname($out), 0o777, true);
        }
        $cmd = sprintf('mongodump --uri=%s --db=hiyori --gzip --archive=%s >/dev/null 2>&1 &', escapeshellarg($_ENV['MONGODB_URL'] ?? 'mongodb://localhost:27017'), escapeshellarg($out));
        @exec($cmd);
        sendJson(['started' => true, 'archive' => basename($out), 'backup' => backupInfo()]);
    }
    sendJson(['backup' => backupInfo(), 'timestamp' => date('c')]);
}

if ($path === '/api/action') {
    $job = (string) ($_GET['job'] ?? '');
    $cmd2 = (string) ($_GET['cmd'] ?? '');
    if ($job === '' || !in_array($cmd2, ['pause', 'resume', 'retry', 'start', 'runnow'], true)) {
        sendJson(['error' => 'job + cmd (pause|resume|retry|start|runnow) required'], 400);
    }
    if (in_array($cmd2, ['retry', 'start'], true)) {
        $src = str_starts_with($job, 'ingest-') ? substr($job, 7) : $job;
        $exe = sprintf('cd %s && php hiyori ingest %s --delay 1 >/dev/null 2>&1 &', escapeshellarg(dirname(__DIR__)), escapeshellarg($src));
        @exec($exe);
        auditAppend('job.' . $cmd2, ['job' => $job]);
        sendJson(['job' => $job, 'cmd' => $cmd2, 'scheduled' => true]);
    }
    if ($cmd2 === 'runnow') {
        $srcs = $_ENV['SCHEDULE_SOURCES'] ?? 'anilist,myanimelist,kitsu';
        foreach (explode(',', (string) $srcs) as $src) {
            $src = trim($src);
            if ($src !== '') {
                $exe = sprintf('cd %s && php hiyori ingest %s --delay 1 >/dev/null 2>&1 &', escapeshellarg(dirname(__DIR__)), escapeshellarg($src));
                @exec($exe);
            }
        }
        auditAppend('schedule.runnow', ['sources' => (string) $srcs]);
        sendJson(['job' => $job, 'cmd' => 'runnow', 'scheduled' => true]);
    }
    // The worker reads `control-<source>.json` (Telemetry::isPaused), and the
    // status payload reads the same name via controls() — which strips the
    // `ingest-` prefix. This handler was the only writer using the prefixed job
    // name, so pause wrote control-ingest-<source>.json while the worker waited
    // on control-<source>.json and never saw it. Normalise to the source name.
    $sourceName = str_starts_with($job, 'ingest-') ? substr($job, 7) : $job;
    file_put_contents(
        dirname(__DIR__) . '/storage/state/control-' . $sourceName . '.json',
        json_encode(['paused' => $cmd2 === 'pause', 'updated_at' => date('c')]),
    );
    auditAppend('job.' . $cmd2, ['job' => $job]);

    sendJson(['job' => $job, 'cmd' => $cmd2, 'control' => controls($sourceName)]);
}

/**
 * Rebuild the merged collections using the weights currently saved in
 * Settings.
 *
 * The weight sliders persisted `prefs.weights` and then handed the operator a
 * CLI command to copy, which made the dashboard a settings form with no apply
 * button. This closes that loop: the same weights it stores are the ones the
 * next combine runs with.
 *
 * Runs detached like the ingest actions — a combine over these collections
 * takes tens of minutes, so the request returns as soon as the process starts
 * and progress shows up in the normal job telemetry.
 */
if ($path === '/api/combine') {
    $base = (string) ($_GET['base'] ?? 'anilist');
    $strategy = (string) ($_GET['strategy'] ?? 'metadata_fuzzy');
    if (!preg_match('/^[a-z_]+$/', $base) || !preg_match('/^[a-z_]+$/', $strategy)) {
        sendJson(['error' => 'base and strategy must be lowercase identifiers'], 400);
    }

    $weights = uiSettings()['prefs']['weights'] ?? null;
    $args = sprintf('combine %s --strategy %s', escapeshellarg($base), escapeshellarg($strategy));
    if (is_array($weights) && $weights !== []) {
        $args .= ' --weights ' . escapeshellarg((string) json_encode($weights));
    }
    // Passthrough is required on every release path; mirrors the CLI contract.
    $args .= ' --passthrough';

    // `echo $!` gives us the child pid so combineRunning() can tell a live run
    // from a marker left behind by one that died.
    $exe = sprintf('cd %s && php hiyori %s >/dev/null 2>&1 & echo $!', escapeshellarg(dirname(__DIR__)), $args);
    $pid = (int) trim((string) @shell_exec($exe));
    @file_put_contents(
        dirname(__DIR__) . '/storage/state/combine-run.json',
        json_encode(['pid' => $pid, 'started_at' => date('c'), 'base' => $base, 'strategy' => $strategy]),
    );
    auditAppend('combine.start', ['base' => $base, 'strategy' => $strategy, 'pid' => $pid, 'weights' => $weights]);

    sendJson(['started' => true, 'base' => $base, 'strategy' => $strategy, 'pid' => $pid, 'weights' => $weights]);
}

if ($path === '/api/coverage') {
    sendJson(['coverage' => coverage($client), 'timestamp' => date('c')]);
}

if ($path === '/api/benchmark') {
    sendJson(benchmarkData($telemetry));
}

if ($path === '/api/alerts') {
    sendJson(['alerts' => alerts(ttlCache('health', 60, static fn () => $health->check()), $telemetry), 'timestamp' => date('c')]);
}

if ($path === '/api/sample') {
    $collection = (string) ($_GET['collection'] ?? 'organized');
    $min = (float) ($_GET['min'] ?? -1);
    // Merged records carry `confidence` (0-100) — there is no `score` field, so
    // the old `score` filter matched nothing and "Draw a sample" came back empty
    // at every min >= 0.
    $field = (string) ($_GET['field'] ?? 'confidence');
    if (!in_array($field, ['confidence', 'score', 'ml_score'], true)) {
        sendJson(['error' => 'field must be one of confidence, score, ml_score'], 400);
    }
    $pipeline = [];
    if ($min >= 0) {
        $pipeline[] = ['$match' => [$field => ['$gte' => $min]]];
    }
    $pipeline[] = ['$sample' => ['size' => 1]];
    $doc = $client->selectDatabase('hiyori')->selectCollection($collection)->aggregate($pipeline)->toArray()[0] ?? null;
    $sample = $doc === null ? null : json_decode(json_encode($doc), true);
    // The source titles behind the reference keys: a reviewer cannot judge a
    // merge from the merged title alone.
    $refs = is_array($sample['reference_ids'] ?? null) ? $sample['reference_ids'] : [];
    sendJson([
        'sample' => $sample,
        'refs' => sampleSourceTitles($client, $refs),
        'min' => $min,
        'field' => $field,
    ]);
}

if ($path === '/api/labels') {
    $store = LabelStore::create($database);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJsonBody();
        $ok = $store->upsert([
            'base_source' => (string) ($body['base_source'] ?? ''),
            'base_id' => (string) ($body['base_id'] ?? ''),
            'candidate_source' => (string) ($body['candidate_source'] ?? ''),
            'candidate_id' => (string) ($body['candidate_id'] ?? ''),
            'label' => (int) ($body['label'] ?? 0),
            'source' => (string) ($body['source'] ?? 'human'),
            'notes' => (string) ($body['notes'] ?? ''),
            'features' => is_array($body['features'] ?? null) ? $body['features'] : null,
        ]);
        if (!$ok) {
            sendJson(['error' => 'base_source, base_id, candidate_source and candidate_id are required'], 400);
        }
        sendJson(['ok' => true]);
    }
    sendJson(['labels' => $store->stats()]);
}

if ($path === '/api/rejections') {
    $store = RejectionStore::create($database);
    $store->ensureIndexes();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJsonBody();
        $ok = $store->upsert([
            'base_source' => (string) ($body['base_source'] ?? ''),
            'base_id' => (string) ($body['base_id'] ?? ''),
            'candidate_source' => (string) ($body['candidate_source'] ?? ''),
            'candidate_id' => (string) ($body['candidate_id'] ?? ''),
            'decision' => (string) ($body['decision'] ?? 'reject'),
            'source' => (string) ($body['source'] ?? 'human'),
            'notes' => (string) ($body['notes'] ?? ''),
            'score' => $body['score'] ?? null,
        ]);
        if (!$ok) {
            sendJson(['error' => 'base_source, base_id, candidate_source and candidate_id are required'], 400);
        }
        sendJson(['ok' => true]);
    }
    $decision = (string) ($_GET['decision'] ?? '');
    sendJson([
        'stats' => $store->stats(),
        'rejections' => $store->list($decision !== '' ? $decision : null, (int) ($_GET['limit'] ?? 50)),
    ]);
}

if ($path === '/api/rejections/promote') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['error' => 'promote requires POST'], 405);
    }
    $store = RejectionStore::create($database);
    $store->ensureIndexes();
    $body = readJsonBody();
    sendJson($store->promote(
        (string) ($body['decision'] ?? '') !== '' ? (string) ($body['decision'] ?? '') : null,
        isset($body['limit']) && (int) $body['limit'] > 0 ? (int) $body['limit'] : null,
    ));
}

if ($path === '/api/jobsV2') {
    sendJson(['jobs' => $telemetry->allState(), 'timestamp' => date('c')]);
}

if ($path === '/api/slow') {
    sendJson(['slow' => $telemetry->tail('slow-requests', 50), 'timestamp' => date('c')]);
}

if ($path === '/api/schedule') {
    sendJson(['schedule' => scheduleView($telemetry), 'timestamp' => date('c')]);
}

if ($path === '/api/status') {
    $controlsMap = [];
    foreach (array_keys($telemetry->allState()) as $k) {
        if (str_starts_with($k, 'ingest-')) {
            $controlsMap[$k] = controls(substr($k, 7));
        }
    }

    // The external health probe hits three APIs, so it is TTL-cached: a
    // fresh-enough view without probing on every 3s/15s poll. Counts and
    // coverage stay live (one aggregation pass each).
    $counts = collectionCounts($client);
    $healthResult = ttlCache('health', 60, static fn () => $health->check());

    sendJson([
        'timestamp' => date('c'),
        'counts' => $counts,
        'db' => dbStats($client, $counts),
        'jobs' => $telemetry->allState(),
        'controls' => $controlsMap,
        'backup' => backupInfo(),
        'cache' => $telemetry->read('cache'),
        'coverage' => coverage($client, $counts),
        'alerts' => alerts($healthResult, $telemetry),
        'slow' => $telemetry->tail('slow-requests', 30),
        'schedule' => scheduleView($telemetry),
        'health' => $healthResult,
        'validation' => $telemetry->tail('validation-log', 40),
        'combine' => $telemetry->read('combine-latest'),
        'combine_running' => combineRunning(),
    ]);
}

if ($path === '/api/events') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-store');
    while (true) {
        echo 'data: ' . json_encode([
            'timestamp' => date('c'),
            'counts' => collectionCounts($client),
            'jobs' => $telemetry->allState(),
        ], JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        flush();
        if (connection_aborted()) {
            break;
        }
        sleep(3);
    }
    exit;
}

// ===== Dashboard v2 endpoints =====
/**
 * Whether a combine started from the dashboard is still running.
 *
 * There is no per-run telemetry for combine (unlike ingest, which writes a
 * state file), so the start endpoint stamps a marker with the process id and
 * this checks the process is still alive. A stale marker — the process died,
 * the box rebooted — clears itself rather than leaving the Rebuild button
 * disabled forever.
 */
function combineRunning(): bool
{
    $file = dirname(__DIR__) . '/storage/state/combine-run.json';
    if (!is_file($file)) {
        return false;
    }
    $state = json_decode((string) @file_get_contents($file), true);
    $pid = (int) ($state['pid'] ?? 0);
    if ($pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0)) {
        return true;
    }
    @unlink($file);

    return false;
}

function uiSettings(): array
{
    $dir = dirname(__DIR__) . '/storage/state';
    $file = $dir . '/ui.json';
    $settings = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

    // Defaults. `_counters`/`_migrations` are internal bookkeeping, not dataset
    // documents, so they start hidden — otherwise the Dataset donut shows
    // meaningless 1-doc and 4-doc slices. Settings → Collections can unhide them.
    $settings['prefs'] = ($settings['prefs'] ?? []) + [
        'realtime' => true,
        'hiddenCollections' => ['_counters', '_migrations'],
    ];

    return $settings;
}

function saveUiSettings(array $s): void
{
    $dir = dirname(__DIR__) . '/storage/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/ui.json', json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function auditAppend(string $action, array $detail = []): void
{
    $dir = dirname(__DIR__) . '/storage/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/audit.jsonl', json_encode([
        'ts' => date('c'),
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'detail' => $detail,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

/**
 * Parse the last $n JSON lines of a JSONL file.
 *
 * `total` counts every non-empty line in the file, so callers can be honest
 * about truncation ("newest 500 of 2,377") instead of implying the tail is all.
 *
 * @return array{total: int, events: list<array<string, mixed>>}
 */
function tailLinesMeta(string $file, int $n): array
{
    if (!is_file($file)) {
        return ['total' => 0, 'events' => []];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $cut = count($lines) - $n;
    $total = 0;
    $events = [];
    foreach ($lines as $i => $line) {
        if (trim($line) === '') {
            continue;
        }
        ++$total;
        if ($i < $cut) {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    return ['total' => $total, 'events' => $events];
}

function tailLines(string $file, int $n): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $tail = array_slice($lines, max(0, count($lines) - $n));
    $out = [];
    foreach ($tail as $l) {
        $decoded = json_decode($l, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

function backupsList(): array
{
    $dir = dirname(__DIR__) . '/storage/backups';
    // Any .gz in the backups dir is restorable, not just the `hiyori-<ts>.gz`
    // ones this app writes — manual archives (e.g. pre-rebuild snapshots) are
    // valid backups too and must show up in the list.
    $files = glob($dir . '/*.gz') ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) - filemtime($a));

    return array_map(static fn ($f) => ['file' => basename($f), 'mtime' => filemtime($f), 'size' => filesize($f)], $files);
}

function histogram(MongoClient $client, string $collection = 'organized', ?string $field = null): array
{
    $db = $client->selectDatabase('hiyori');
    $bins = [];
    $fields = $field !== null ? [$field] : ['score', 'confidence'];
    foreach ($fields as $field) {
        try {
            $pipeline = [
                ['$match' => [$field => ['$exists' => true]]],
                ['$group' => ['_id' => ['$floor' => ['$multiply' => [['$divide' => ['$' . $field, 10]], 1.0]]], 'n' => ['$sum' => 1]]],
            ];
            foreach ($db->selectCollection($collection)->aggregate($pipeline)->toArray() as $r) {
                $bin = (int) ($r['_id'] ?? -1);
                if ($bin >= 0 && $bin <= 10) {
                    $bins[min(9, $bin) * 10] = (int) ($bins[min(9, $bin) * 10] ?? 0) + (int) ($r['n'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            // fall through to next field
        }
    }

    $out = [];
    for ($i = 0; $i < 10; $i++) {
        $out[$i * 10] = $bins[$i * 10] ?? 0;
    }

    return $out;
}

function exploreCollection(MongoClient $client, string $collection): \MongoDB\Collection
{
    return $client->selectDatabase('hiyori')->selectCollection($collection);
}

function readJsonBody(): array
{
    return json_decode((string) file_get_contents('php://input'), true) ?: [];
}

function facetValues(\MongoDB\Collection $coll, string $field, array $mongoFilter): array
{
    $out = [];
    try {
        foreach ($coll->aggregate(Explorer::facetPipeline($field, $mongoFilter)) as $r) {
            $v = $r['_id'] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $out[] = ['v' => (string) $v, 'n' => (int) ($r['n'] ?? 0)];
        }
    } catch (\Throwable $e) {
        // field may not exist on this collection — return an empty facet
    }

    return $out;
}

function explore(MongoClient $client, array $q): array
{
    $collection = Explorer::collectionName((string) ($q['collection'] ?? 'anilist'));
    if ($collection === '') {
        return ['error' => 'bad collection'];
    }
    $page = max(1, (int) ($q['page'] ?? 1));
    $per = min(100, max(1, (int) ($q['per'] ?? 25)));
    $sort = (string) ($q['sort'] ?? '');
    $dir = (strtolower((string) ($q['dir'] ?? 'asc')) === 'desc') ? -1 : 1;

    // ?regex=1 → keep the search term as a raw PCRE pattern (frontend regex
    // toggle); invalid/oversized patterns fall back to escaped-literal matching.
    $regex = (($q['regex'] ?? '') === '1');

    $coll = exploreCollection($client, $collection);
    $mongoFilter = Explorer::buildFilter($q, $regex);

    $total = $coll->countDocuments($mongoFilter);
    $opts = ['skip' => ($page - 1) * $per, 'limit' => $per];
    if ($sort !== '' && Explorer::fieldPath($sort) !== '') {
        $opts['sort'] = [$sort => $dir];
    }
    $rows = [];
    foreach ($coll->find($mongoFilter, $opts) as $doc) {
        $rows[] = json_decode(json_encode($doc), true);
    }
    $fields = $rows !== [] ? array_keys($rows[0]) : [];

    $out = [
        'collection' => $collection,
        'regex' => $regex,
        'page' => $page,
        'per' => $per,
        'total' => $total,
        'docs' => $rows,
        'fields' => $fields,
    ];

    // Optional distinct-value facets: ?facets=format,status → one aggregation
    // per field, top 12 string values, empty values skipped.
    $facetsParam = trim((string) ($q['facets'] ?? ''));
    if ($facetsParam !== '') {
        $facets = [];
        foreach (array_unique(array_filter(array_map('trim', explode(',', $facetsParam)))) as $f) {
            if (Explorer::fieldPath($f) === '') {
                continue;
            }
            $facets[$f] = facetValues($coll, $f, $mongoFilter);
        }
        $out['facets'] = $facets;
    }

    return $out;
}

function metaMissingCounts(\MongoDB\Collection $coll, array $fields): array
{
    $fields = array_values(array_filter(array_slice($fields, 0, 24), static fn ($f): bool => Explorer::fieldPath((string) $f) !== ''));
    if ($fields === []) {
        return [];
    }
    $group = ['_id' => null];
    foreach ($fields as $f) {
        $group['n_' . str_replace('.', '__', $f)] = [
            '$sum' => ['$cond' => [['$eq' => [['$ifNull' => ['$' . $f, null]], null]], 1, 0]],
        ];
    }
    $row = $coll->aggregate([['$group' => $group]])->toArray()[0] ?? null;
    if ($row === null) {
        return [];
    }
    $missing = [];
    foreach ($fields as $f) {
        $key = 'n_' . str_replace('.', '__', $f);
        $missing[$f] = (int) ($row[$key] ?? 0);
    }

    return $missing;
}

if ($path === '/api/explore') {
    sendJson(explore($client, $_GET));
}

// Restored regression: 10-bin score/confidence histogram (bin width 10 over
// 0..100), collection-selectable via ?collection= (default "organized").
if ($path === '/api/histogram') {
    $collection = Explorer::collectionName((string) ($_GET['collection'] ?? 'organized'));
    if ($collection === '') {
        sendJson(['error' => 'bad collection'], 400);
    }
    $field = (string) ($_GET['field'] ?? '');
    if ($field !== '' && !in_array($field, ['score', 'confidence', 'ml_score'], true)) {
        sendJson(['error' => 'field must be one of score, confidence, ml_score'], 400);
    }
    sendJson([
        'histogram' => histogram($client, $collection, $field !== '' ? $field : null),
        'field' => $field !== '' ? $field : 'score+confidence',
        'bins' => [0, 10, 20, 30, 40, 50, 60, 70, 80, 90],
        'collection' => $collection,
        'timestamp' => date('c'),
    ]);
}

if ($path === '/api/explore/update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['error' => 'POST required'], 405);
    }
    $body = readJsonBody();
    $collection = Explorer::collectionName((string) ($body['collection'] ?? ''));
    $id = (string) ($body['id'] ?? '');
    $field = Explorer::fieldPath((string) ($body['field'] ?? ''));
    if ($collection === '' || $id === '' || $field === '') {
        sendJson(['error' => 'collection, id and field are required'], 400);
    }
    if ($field === '_id') {
        sendJson(['error' => 'field cannot be _id'], 400);
    }
    $coll = exploreCollection($client, $collection);
    $filter = ['_id' => Explorer::docIdValue($id)];
    if (array_key_exists('value', $body) && $body['value'] === null) {
        $res = $coll->updateOne($filter, ['$unset' => [$field => '']]);
    } else {
        $value = $body['value'] ?? null;
        if (!is_scalar($value)) {
            sendJson(['error' => 'value must be a scalar'], 400);
        }
        $res = $coll->updateOne($filter, ['$set' => [$field => $value]]);
    }
    auditAppend('explore.update', ['collection' => $collection, 'id' => $id, 'field' => $field, 'matched' => $res->getMatchedCount()]);
    sendJson([
        'collection' => $collection,
        'id' => $id,
        'field' => $field,
        'matched' => $res->getMatchedCount(),
        'modified' => $res->getModifiedCount(),
        'timestamp' => date('c'),
    ]);
}

if ($path === '/api/explore/bulk') {
    $body = readJsonBody();
    $collection = Explorer::collectionName((string) ($body['collection'] ?? ''));
    $action = (string) ($body['action'] ?? '');
    if ($collection === '' || !in_array($action, ['set', 'delete', 'delete_soft'], true)) {
        sendJson(['error' => 'collection and action (set|delete|delete_soft) are required'], 400);
    }
    $ids = array_values(array_filter(array_map('strval', (array) ($body['ids'] ?? []))));
    if ($ids === [] || count($ids) > Explorer::MAX_IMPORT_ROWS) {
        sendJson(['error' => 'ids required (1..10000)'], 400);
    }
    $coll = exploreCollection($client, $collection);
    $idFilter = ['_id' => ['$in' => array_map([Explorer::class, 'docIdValue'], $ids)]];

    if ($action === 'set') {
        $key = Explorer::fieldPath((string) ($body['key'] ?? ''));
        if ($key === '' || $key === '_id') {
            sendJson(['error' => 'bad key'], 400);
        }
        $value = $body['value'] ?? null;
        if ($value === null) {
            $res = $coll->updateMany($idFilter, ['$unset' => [$key => '']]);
        } else {
            if (!is_scalar($value)) {
                sendJson(['error' => 'value must be a scalar'], 400);
            }
            $res = $coll->updateMany($idFilter, ['$set' => [$key => $value]]);
        }
        auditAppend('explore.bulk.set', ['collection' => $collection, 'ids' => count($ids), 'key' => $key]);
        sendJson([
            'action' => $action,
            'collection' => $collection,
            'affected' => $res->getMatchedCount(),
            'matched' => $res->getMatchedCount(),
            'modified' => $res->getModifiedCount(),
            'timestamp' => date('c'),
        ]);
    }

    // delete / delete_soft: snapshot the docs to explore_undo_<collection>
    // first (with _deletedAt + _batch) so the batch can be restored later.
    $batch = time();
    $snapshot = [];
    foreach ($coll->find($idFilter, ['limit' => Explorer::MAX_IMPORT_ROWS]) as $doc) {
        $arr = $doc instanceof \MongoDB\Model\BSONDocument ? $doc->getArrayCopy() : (array) $doc;
        $arr['_deletedAt'] = $batch;
        $arr['_batch'] = $batch;
        $snapshot[] = $arr;
    }
    if ($snapshot !== []) {
        exploreCollection($client, 'explore_undo_' . $collection)->insertMany($snapshot, ['ordered' => false]);
    }

    if ($action === 'delete') {
        $res = $coll->deleteMany($idFilter);
        auditAppend('explore.bulk.delete', ['collection' => $collection, 'ids' => count($ids), 'deleted' => $res->getDeletedCount(), 'batch' => $batch]);
        sendJson([
            'action' => $action,
            'collection' => $collection,
            'affected' => $res->getDeletedCount(),
            'snapshotted' => count($snapshot),
            'batch' => $batch,
            'timestamp' => date('c'),
        ]);
    }

    // delete_soft: keep a copy flagged in place, don't remove the docs.
    $res = $coll->updateMany($idFilter, ['$set' => ['_deletedAt' => $batch]]);
    auditAppend('explore.bulk.delete_soft', ['collection' => $collection, 'ids' => count($ids), 'flagged' => $res->getMatchedCount(), 'batch' => $batch]);
    sendJson([
        'action' => $action,
        'collection' => $collection,
        'affected' => $res->getMatchedCount(),
        'snapshotted' => count($snapshot),
        'batch' => $batch,
        'timestamp' => date('c'),
    ]);
}

if ($path === '/api/explore/undo') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // GET /api/explore/undo?collection=x → recent batches (count, time)
        $collection = Explorer::collectionName((string) ($_GET['collection'] ?? ''));
        if ($collection === '') {
            sendJson(['error' => 'bad collection'], 400);
        }
        $undo = exploreCollection($client, 'explore_undo_' . $collection);
        $batches = [];
        foreach ($undo->aggregate([
            ['$group' => ['_id' => '$_batch', 'count' => ['$sum' => 1], 'time' => ['$max' => '$_deletedAt']]],
            ['$sort' => ['time' => -1]],
            ['$limit' => 20],
        ]) as $r) {
            $ts = (int) ($r['time'] ?? 0);
            $batches[] = [
                'batch' => (int) ($r['_id'] ?? 0),
                'count' => (int) ($r['count'] ?? 0),
                'time' => $ts,
                'seconds_ago' => $ts > 0 ? time() - $ts : null,
            ];
        }
        sendJson(['collection' => $collection, 'batches' => $batches, 'timestamp' => date('c')]);
    }

    // POST /api/explore/undo {collection, batch?} → restore the most recent
    // (or the given) batch from explore_undo_<collection> back into it.
    $body = readJsonBody();
    $collection = Explorer::collectionName((string) ($body['collection'] ?? ''));
    if ($collection === '') {
        sendJson(['error' => 'bad collection'], 400);
    }
    $undo = exploreCollection($client, 'explore_undo_' . $collection);
    $batch = isset($body['batch']) ? (int) $body['batch'] : 0;
    if ($batch > 0) {
        $filter = ['_batch' => $batch];
    } else {
        $latest = $undo->findOne([], ['sort' => ['_deletedAt' => -1]]);
        if ($latest === null) {
            sendJson(['collection' => $collection, 'restored' => 0, 'batch' => null, 'timestamp' => date('c')]);
        }
        $batch = (int) ($latest['_batch'] ?? 0);
        $filter = ['_batch' => $batch];
    }
    $docs = $undo->find($filter)->toArray();
    if ($docs === []) {
        sendJson(['collection' => $collection, 'restored' => 0, 'batch' => $batch, 'timestamp' => date('c')]);
    }
    $ops = [];
    foreach ($docs as $doc) {
        if (count($ops) >= Explorer::MAX_IMPORT_ROWS) {
            break;
        }
        $arr = $doc instanceof \MongoDB\Model\BSONDocument ? $doc->getArrayCopy() : (array) $doc;
        $id = $arr['_id'] ?? null;
        if ($id === null) {
            continue;
        }
        unset($arr['_id'], $arr['_deletedAt'], $arr['_batch']);
        $ops[] = ['updateOne' => [
            ['_id' => $id],
            ['$set' => $arr, '$unset' => ['_deletedAt' => '']],
            ['upsert' => true],
        ]];
    }
    $res = exploreCollection($client, $collection)->bulkWrite($ops, ['ordered' => false]);
    $restored = $res->getUpsertedCount() + $res->getModifiedCount();
    $removed = $undo->deleteMany(['_batch' => $batch])->getDeletedCount();
    auditAppend('explore.undo', ['collection' => $collection, 'batch' => $batch, 'restored' => $restored]);
    sendJson([
        'collection' => $collection,
        'batch' => $batch,
        'restored' => $restored,
        'removed' => $removed,
        'timestamp' => date('c'),
    ]);
}

if ($path === '/api/explore/import') {
    $body = readJsonBody();
    $collection = Explorer::collectionName((string) ($body['collection'] ?? ''));
    $csv = (string) ($body['csv'] ?? '');
    $keyField = Explorer::fieldPath((string) ($body['keyField'] ?? '_id')) ?: '_id';
    if ($collection === '' || $csv === '') {
        sendJson(['error' => 'collection and csv are required'], 400);
    }
    $rows = Explorer::parseCsv($csv);
    if ($rows === []) {
        sendJson(['error' => 'no data rows in csv'], 400);
    }
    $coll = exploreCollection($client, $collection);
    $ops = [];
    $skipped = 0;
    foreach ($rows as $row) {
        $key = $row[$keyField] ?? null;
        if ($key === null || $key === '') {
            ++$skipped;
            continue;
        }
        $filter = $keyField === '_id' ? ['_id' => Explorer::docIdValue((string) $key)] : [$keyField => $key];
        unset($row[$keyField]);
        $ops[] = ['updateOne' => [$filter, ['$set' => $row], ['upsert' => true]]];
        if (count($ops) >= Explorer::MAX_IMPORT_ROWS) {
            break;
        }
    }
    if ($ops === []) {
        sendJson(['error' => 'no upsertable rows (keyField missing or empty)'], 400);
    }
    $res = $coll->bulkWrite($ops, ['ordered' => false]);
    auditAppend('explore.import', ['collection' => $collection, 'keyField' => $keyField, 'rows' => count($ops), 'upserted' => $res->getUpsertedCount(), 'modified' => $res->getModifiedCount()]);
    sendJson([
        'collection' => $collection,
        'imported' => $res->getUpsertedCount(),
        'updated' => $res->getModifiedCount(),
        'matched' => $res->getMatchedCount(),
        'rows' => count($ops),
        'skipped' => $skipped,
        'timestamp' => date('c'),
    ]);
}

if ($path === '/api/meta') {
    $cols = [];
    $db = $client->selectDatabase('hiyori');
    foreach ($db->listCollections() as $c) {
        $name = $c->getName();
        $coll = $db->selectCollection($name);
        $doc = $coll->findOne();
        $fields = $doc ? array_keys(json_decode(json_encode($doc), true)) : [];
        $cols[$name] = [
            'count' => $coll->countDocuments(),
            'fields' => array_slice($fields, 0, 24),
            'missing' => metaMissingCounts($coll, $fields),
        ];
    }
    sendJson(['collections' => $cols, 'timestamp' => date('c')]);
}

if ($path === '/api/settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        if (isset($body['token_rotate'])) {
            $token = bin2hex(random_bytes(24));
            updateDotEnv('DASHBOARD_TOKEN', $token);
            auditAppend('settings.token_rotate');
            sendJson(['settings' => uiSettings(), 'token' => $token, 'note' => 'new token active on next request']);
        }
        if (array_key_exists('webhook', $body)) {
            updateDotEnv('WEBHOOK_URL', (string) $body['webhook']);
            $merged = array_merge(uiSettings(), array_diff_key($body, ['webhook' => true]));
            saveUiSettings($merged);
            auditAppend('settings.webhook');
            sendJson(['settings' => $merged, 'webhook' => (string) $body['webhook']]);
        }
        if (isset($body['webhook_test']) && $body['webhook_test']) {
            $hook = $_ENV['WEBHOOK_URL'] ?? '';
            if ($hook === '') {
                sendJson(['test' => 'no webhook configured'], 400);
            }
            $ok = @file_get_contents($hook, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode(['text' => '[Hiyori] dashboard webhook test']),
                    'timeout' => 5,
                ],
            ]));
            auditAppend('settings.webhook_test', ['sent' => $ok !== false]);
            sendJson(['test' => $ok !== false ? 'sent' : 'failed']);
        }
        if (isset($body['reset']) && $body['reset']) {
            @unlink(dirname(__DIR__) . '/storage/state/ui.json');
            auditAppend('settings.reset');
            sendJson(['settings' => [], 'reset' => true]);
        }
        $merged = array_merge(uiSettings(), $body);
        if (isset($body['prefs']) && is_array($body['prefs'])) {
            $prevPrefs = (array) (uiSettings()['prefs'] ?? []);
            $merged['prefs'] = array_merge($prevPrefs, $body['prefs']);
        }
        saveUiSettings($merged);
        auditAppend('settings.update', ['keys' => array_keys($body)]);
        sendJson(['settings' => $merged]);
    }
    sendJson([
        'settings' => uiSettings(),
        'env' => [
            'token_present' => isset($_ENV['DASHBOARD_TOKEN']) && $_ENV['DASHBOARD_TOKEN'] !== '',
            'webhook' => $_ENV['WEBHOOK_URL'] ?? '',
            'rate_max' => (int) ($_ENV['RATE_LIMIT_MAX'] ?? 100),
            'rate_window' => (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
        ],
        'timestamp' => date('c'),
    ]);
}

// ===== Environment helpers (.env read/write + sensitivity) =====
function envFile(): string
{
    return dirname(__DIR__) . '/.env';
}

function listEnvVars(): array
{
    $file = envFile();
    if (!is_file($file)) {
        return [];
    }
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $out[trim($key)] = $value;
    }

    return $out;
}

function isSensitiveKey(string $key): bool
{
    if ((bool) preg_match('/(TOKEN|SECRET|PASSWORD|CLIENT_SECRET)/i', $key)) {
        return true;
    }
    $ui = uiSettings();
    foreach ((array) ($ui['env_sensitive_patterns'] ?? []) as $pattern) {
        $pattern = (string) $pattern;
        if ($pattern !== '' && @preg_match($pattern, $key)) {
            return true;
        }
    }

    return false;
}

function maskSecret(string $value): string
{
    if ($value === '') {
        return '';
    }
    $head = array_slice(str_split($value), 0, 4);

    return implode('', $head) . '••••' . (strlen($value) > 8 ? '(' . strlen($value) . ' chars)' : '');
}

function removeEnvVar(string $key): void
{
    $file = envFile();
    if (!is_file($file)) {
        return;
    }
    $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES) ?: [], static function ($l) use ($key) {
        $t = ltrim($l);
        if ($t === '' || str_starts_with($t, '#')) {
            return true; // keep blank + comment lines
        }

        return !str_starts_with($t, $key . '=');
    });
    @copy($file, $file . '.bak');
    file_put_contents($file, implode("\n", array_values($lines)) . "\n");
}

function envRaw(): string
{
    $file = envFile();

    return is_file($file) ? (string) file_get_contents($file) : '';
}

function writeEnvRaw(string $raw): void
{
    $file = envFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    @copy($file, $file . '.bak');
    file_put_contents($file, rtrim($raw) . "\n");
}

function updateDotEnv(string $key, ?string $value): bool
{
    $envFile = envFile();
    if (!is_file($envFile)) {
        return false;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '=/', $line)) {
            $lines[$i] = $key . '=' . ($value ?? '');
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . ($value ?? '');
    }
    @copy($envFile, $envFile . '.bak');
    file_put_contents($envFile, implode("\n", $lines) . "\n");

    return true;
}


/**
 * Reject requests that did not originate from this dashboard.
 *
 * No route had any Origin, Referer or Sec-Fetch check, so `/api/env?raw=1`
 * (which returns the whole .env) and `?reveal=<key>` were readable by any page
 * the operator happened to visit — DNS rebinding is enough, because the
 * attacker's page resolves to 127.0.0.1 and the browser then treats it as
 * same-origin at the network layer.
 *
 * A browser always sends Sec-Fetch-Site, and sends Origin on anything
 * cross-origin. Both are checked. Non-browser clients (curl, the CLI) send
 * neither and are allowed through, which keeps the local tool usable.
 *
 * This is the right defence for a loopback tool; setting DASHBOARD_TOKEN adds
 * real authentication on top. Neither replaces the other.
 */
function sameOriginRequest(): bool
{
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site === 'cross-site') {
        return false;
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }

    $host = (string) (parse_url($origin, PHP_URL_HOST) ?? '');
    $reqHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $reqName = explode(':', $reqHost)[0];

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $host === $reqName;
}

/** 403 unless the request came from this dashboard. */
function requireSameOrigin(): void
{
    if (!sameOriginRequest()) {
        sendJson(['error' => 'cross-origin request refused'], 403);
    }
}

if ($path === '/api/env' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    requireSameOrigin();
    $vars = listEnvVars();
    $reveal = (string) ($_GET['reveal'] ?? '');
    if (($_GET['raw'] ?? '') === '1') {
        sendJson(['raw' => envRaw(), 'source' => '.env']);
    }
    $rows = [];
    foreach ($vars as $key => $value) {
        $sensitive = isSensitiveKey($key);
        $rows[] = [
            'key' => $key,
            'present' => $value !== '',
            'sensitive' => $sensitive,
            'masked' => $value === '' ? '' : ($sensitive ? maskSecret((string) $value) : (string) $value),
            'value' => ($key === $reveal && $reveal !== '') ? (string) $value : null,
        ];
    }
    sendJson(['env' => $rows, 'source' => '.env', 'timestamp' => date('c')]);
}

if ($path === '/api/env' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSameOrigin();
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];

    if (isset($body['raw'])) {
        writeEnvRaw((string) $body['raw']);
        auditAppend('env.bulk', ['chars' => strlen((string) $body['raw'])]);
        $vars = listEnvVars();
        $out = [];
        foreach ($vars as $key => $value) {
            $sensitive = isSensitiveKey($key);
            $out[] = ['key' => $key, 'present' => $value !== '', 'sensitive' => $sensitive, 'masked' => $value === '' ? '' : ($sensitive ? maskSecret((string) $value) : (string) $value)];
        }
        sendJson(['raw_updated' => true, 'env' => $out]);
    }

    if (isset($body['patterns'])) {
        $patterns = array_values(array_filter(array_map('strval', (array) $body['patterns'])));
        saveUiSettings(array_merge(uiSettings(), ['env_sensitive_patterns' => $patterns]));
        auditAppend('env.patterns', ['count' => count($patterns)]);
        sendJson(['patterns' => $patterns]);
    }

    $key = trim((string) ($body['key'] ?? ''));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
        sendJson(['error' => 'invalid env key'], 400);
    }
    if (array_key_exists('value', $body) && $body['value'] === null) {
        removeEnvVar($key);
        auditAppend('env.remove', ['key' => $key]);
    } else {
        $value = (string) ($body['value'] ?? '');
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            sendJson(['error' => 'value must be a single line'], 400);
        }
        updateDotEnv($key, $value);
        auditAppend('env.update', ['key' => $key]);
    }
    $vars = listEnvVars();
    sendJson(['updated' => $key, 'env' => array_map(static function ($k) use ($vars) {
        $sensitive = isSensitiveKey($k);
        $value = $vars[$k] ?? '';

        return ['key' => $k, 'present' => $value !== '', 'sensitive' => $sensitive, 'masked' => $value === '' ? '' : ($sensitive ? maskSecret((string) $value) : (string) $value)];
    }, array_keys($vars))]);
}

// Serve backup archives as downloads (they live under storage/backups).
if (str_starts_with($path, '/storage/backups/')) {
    $file = containedFile(substr($path, strlen('/storage/backups/')), dirname(__DIR__) . '/storage/backups');
    if ($file !== null) {
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    sendJson(['error' => 'backup not found'], 404);
}

if ($path === '/api/benchmark-manga') {
    $rows = readBenchmarkLog(dirname(__DIR__) . '/storage/logs/strategy-benchmark-manga.log');
    $out = [];
    foreach ($rows as $row) {
        $strategy = (string) ($row['strategy'] ?? '');
        $total = (int) ($row['total'] ?? 0);
        $wall = (float) ($row['wall_s'] ?? $row['wall'] ?? 0);
        $out[] = [
            'strategy' => $strategy,
            'wall_s' => (int) $wall,
            'merges' => $total,
            'organized' => $total,
            'dups' => (int) ($row['duplicate_keys'] ?? 0),
            'speed' => $wall > 0 ? round($total / $wall, 2) : 0,
        ];
    }
    sendJson(['benchmark' => $out, 'source' => 'strategy-benchmark-manga.log', 'timestamp' => date('c')]);
}

sendJson(['error' => 'not found', 'path' => $path], 404);
