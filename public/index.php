<?php

declare(strict_types=1);

/**
 * Live dashboard front controller for `php -S` router mode.
 *
 * Run via: php hiyori dashboard:serve --port 8080
 * Alternatively: php -S 127.0.0.1:8080 public/index.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Hiyori\Service\AlertNotifier;
use Hiyori\Service\Database;
use Hiyori\Service\Explorer;
use Hiyori\Service\HealthCheck;
use Hiyori\Service\MetricsCollector;
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

// PWA static files
if (in_array($path, ['/sw.js', '/favicon.svg', '/manifest.webmanifest'], true)) {
    $file = dirname(__DIR__) . '/web/public' . $path;
    if (!is_file($file)) {
        $file = dirname(__DIR__) . '/public' . $path;
    }
    if (is_file($file)) {
        header('Content-Type: ' . (str_ends_with($path, '.js') ? 'application/javascript' : (str_ends_with($path, '.svg') ? 'image/svg+xml' : 'application/manifest+json')));
        header('Cache-Control: no-cache');
        echo file_get_contents($file);
        exit;
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

// Static assets (vendored libs) — safe allowlist of extensions.
if (str_starts_with($path, '/vendor/')) {
    $file = __DIR__ . str_replace(['..', '//'], '', $path);
    if (is_file($file)) {
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


function dbStats(MongoClient $client): array
{
    $db = $client->selectDatabase('hiyori');
    $stats = $db->command(['dbstats' => 1])->toArray()[0] ?? [];
    $out = [
        'dataSize' => (int) ($stats['dataSize'] ?? 0),
        'indexSize' => (int) ($stats['indexSize'] ?? 0),
        'storageSize' => (int) ($stats['storageSize'] ?? 0),
        'collections' => [],
    ];
    $total = 0;
    foreach ($db->listCollections() as $col) {
        $n = $db->selectCollection($col->getName())->countDocuments();
        $total += $n;
        $out['collections'][$col->getName()] = $n;
    }
    $out['totalDocuments'] = $total;

    return $out;
}

function backupInfo(): array
{
    $dir = dirname(__DIR__) . '/storage/backups';
    $files = glob($dir . '/hiyori-*.gz') ?: [];
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


function coverage(MongoClient $client): array
{
    $db = $client->selectDatabase('hiyori');
    $out = [];
    $shorthands = ['al', 'mal', 'k', 'adb', 'ann', 'b', 'cr', 'fm', 'nf', 'sb', 't', 'ttv', 'tvt', 'tx', 'wpen', 'wpja', 'yt', 'ap'];
    foreach ($db->listCollections() as $col) {
        $name = $col->getName();
        $coll = $db->selectCollection($name);
        $total = $coll->countDocuments();
        $row = ['total' => $total];
        foreach ($shorthands as $sh) {
            $row[$sh] = $coll->countDocuments(['reference_ids.' . $sh => ['$exists' => true]]);
        }
        // missingness: docs missing the 3 main refs
        $row['missing_al'] = $coll->countDocuments(['reference_ids.al' => ['$exists' => false]]);
        $row['missing_mal'] = $coll->countDocuments(['reference_ids.mal' => ['$exists' => false]]);
        $row['missing_k'] = $coll->countDocuments(['reference_ids.k' => ['$exists' => false]]);
        $out[$name] = $row;
    }

    return $out;
}

function benchmarkData(Telemetry $telemetry): array
{
    // Seed from combine telemetry history + hard-coded measured runs so the
    // comparison chart has data even before a fresh benchmark.
    $combine = $telemetry->read('combine-latest');
    $seed = [
        ['strategy' => 'relational_mapping', 'wall_s' => 457, 'merges' => 14638, 'organized' => 8417, 'speed' => 32.0],
        ['strategy' => 'relational_fuzzy', 'wall_s' => 880, 'merges' => 25124, 'organized' => 16492, 'speed' => 28.5],
        ['strategy' => 'metadata_fuzzy', 'wall_s' => 749, 'merges' => 18291, 'organized' => 14933, 'speed' => 24.4],
    ];

    return ['seeded' => $seed, 'latest' => $combine];
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

function alerts(HealthCheck $health, Telemetry $telemetry): array
{
    $out = [];
    $h = $health->check();
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

if ($path === '/api/db') {
    sendJson(['db' => dbStats($client), 'timestamp' => date('c')]);
}

if ($path === '/api/backup') {
    if (($_GET['run'] ?? '') === '1') {
        $out = dirname(__DIR__) . '/storage/backups/hiyori-' . date('Ymd-His') . '.gz';
        if (!is_dir(dirname($out))) {
            mkdir(dirname($out), 0777, true);
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
    file_put_contents(
        dirname(__DIR__) . '/storage/state/control-' . $job . '.json',
        json_encode(['paused' => $cmd2 === 'pause', 'updated_at' => date('c')]),
    );
    auditAppend('job.' . $cmd2, ['job' => $job]);

    sendJson(['job' => $job, 'cmd' => $cmd2, 'control' => controls($job)]);
}

if ($path === '/api/coverage') {
    sendJson(['coverage' => coverage($client), 'timestamp' => date('c')]);
}

if ($path === '/api/benchmark') {
    sendJson(benchmarkData($telemetry));
}

if ($path === '/api/alerts') {
    sendJson(['alerts' => alerts($health, $telemetry), 'timestamp' => date('c')]);
}

if ($path === '/api/sample') {
    $collection = (string) ($_GET['collection'] ?? 'organized');
    $min = (float) ($_GET['min'] ?? -1);
    $pipeline = [];
    if ($min >= 0) {
        $pipeline[] = ['$match' => ['score' => ['$gte' => $min]]];
    }
    $pipeline[] = ['$sample' => ['size' => 1]];
    $doc = $client->selectDatabase('hiyori')->selectCollection($collection)->aggregate($pipeline)->toArray()[0] ?? null;
    sendJson(['sample' => $doc ? json_decode(json_encode($doc), true) : null, 'min' => $min]);
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

    sendJson([
        'timestamp' => date('c'),
        'counts' => collectionCounts($client),
        'db' => dbStats($client),
        'jobs' => $telemetry->allState(),
        'controls' => $controlsMap,
        'backup' => backupInfo(),
        'cache' => $telemetry->read('cache'),
        'coverage' => coverage($client),
        'alerts' => alerts($health, $telemetry),
        'slow' => $telemetry->tail('slow-requests', 30),
        'schedule' => scheduleView($telemetry),
        'health' => $health->check(),
        'validation' => $telemetry->tail('validation-log', 40),
        'combine' => $telemetry->read('combine-latest'),
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
function uiSettings(): array
{
    $dir = dirname(__DIR__) . '/storage/state';
    $file = $dir . '/ui.json';
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

function saveUiSettings(array $s): void
{
    $dir = dirname(__DIR__) . '/storage/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/ui.json', json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function auditAppend(string $action, array $detail = []): void
{
    $dir = dirname(__DIR__) . '/storage/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/audit.jsonl', json_encode([
        'ts' => date('c'),
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'detail' => $detail,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
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
    $files = glob($dir . '/hiyori-*.gz') ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) - filemtime($a));

    return array_map(static fn ($f) => ['file' => basename($f), 'mtime' => filemtime($f), 'size' => filesize($f)], $files);
}

function histogram(MongoClient $client, string $collection = 'organized'): array
{
    $db = $client->selectDatabase('hiyori');
    $bins = [];
    foreach (['score', 'confidence'] as $field) {
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
    sendJson([
        'histogram' => histogram($client, $collection),
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
        mkdir($dir, 0777, true);
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

if ($path === '/api/env' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    $file = dirname(__DIR__) . $path;
    if (is_file($file)) {
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    sendJson(['error' => 'backup not found'], 404);
}

sendJson(['error' => 'not found', 'path' => $path], 404);
