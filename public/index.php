<?php

declare(strict_types=1);

/**
 * Live dashboard front controller for `php -S` router mode.
 *
 * Run via: php hiyori dashboard:serve --port 8080
 * Alternatively: php -S 127.0.0.1:8080 public/index.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Hiyori\Service\Database;
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
    $dir = dirname(__DIR__, 2) . '/storage/backups';
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
    $dir = dirname(__DIR__, 2) . '/storage/state';
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

function alerts(HealthCheck $health): array
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
    if (!($h['checks']['external_apis']['healthy'] ?? true)) {
        $out[] = ['level' => 'critical', 'type' => 'api_down', 'source' => '*', 'msg' => 'One or more external APIs unreachable'];
    }

    return $out;
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
        $out = dirname(__DIR__, 2) . '/storage/backups/hiyori-' . date('Ymd-His') . '.gz';
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
    if ($job === '' || !in_array($cmd2, ['pause', 'resume', 'retry'], true)) {
        sendJson(['error' => 'job + cmd (pause|resume|retry) required'], 400);
    }
    if ($cmd2 === 'retry') {
        $src = str_starts_with($job, 'ingest-') ? substr($job, 7) : $job;
        $exe = sprintf('cd %s && php hiyori ingest %s --delay 1 >/dev/null 2>&1 &', escapeshellarg(dirname(__DIR__, 2)), escapeshellarg($src));
        @exec($exe);
        sendJson(['job' => $job, 'cmd' => 'retry', 'scheduled' => true]);
    }
    $state = uniqid() === '' ? '' : (string) file_put_contents(
        dirname(__DIR__, 2) . '/storage/state/control-' . $job . '.json',
        json_encode(['paused' => $cmd2 === 'pause', 'updated_at' => date('c')]),
    );

    sendJson(['job' => $job, 'cmd' => $cmd2, 'control' => controls($job)]);
}

if ($path === '/api/coverage') {
    sendJson(['coverage' => coverage($client), 'timestamp' => date('c')]);
}

if ($path === '/api/benchmark') {
    sendJson(benchmarkData($telemetry));
}

if ($path === '/api/alerts') {
    sendJson(['alerts' => alerts($health), 'timestamp' => date('c')]);
}

if ($path === '/api/sample') {
    $collection = (string) ($_GET['collection'] ?? 'organized');
    $doc = $client->selectDatabase('hiyori')->selectCollection($collection)->aggregate([['$sample' => ['size' => 1]]])->toArray()[0] ?? null;
    sendJson(['sample' => $doc ? json_decode(json_encode($doc), true) : null]);
}

if ($path === '/api/jobsV2') {
    sendJson(['jobs' => $telemetry->allState(), 'timestamp' => date('c')]);
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
        'alerts' => alerts($health),
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

sendJson(['error' => 'not found', 'path' => $path], 404);
