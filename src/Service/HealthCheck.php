<?php

namespace Hiyori\Service;

use MongoDB\Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class HealthCheck
{
    public function __construct(
        private Client $mongoClient,
        private RateLimiter $rateLimiter
    ) {
    }

    public function check(): array
    {
        $checks = [
            'mongodb' => $this->checkMongoDB(),
            'external_apis' => $this->checkExternalAPIs(),
            'rate_limiter' => $this->checkRateLimiter(),
            'disk_space' => $this->checkDiskSpace(),
            'memory' => $this->checkMemory(),
        ];

        $healthy = true;
        foreach ($checks as $name => $result) {
            if (!($result['healthy'] ?? false)) {
                $healthy = false;
            }
        }

        return [
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => date('c'),
            'checks' => $checks,
        ];
    }

    private function checkMongoDB(): array
    {
        try {
            $start = microtime(true);
            $db = $this->mongoClient->selectDatabase('admin');
            $result = $db->command(['ping' => 1]);
            $duration = microtime(true) - $start;

            $ok = false;
            foreach ($result as $doc) {
                $okVal = $doc['ok'] ?? 0;
                if ((int)$okVal === 1) {
                    $ok = true;
                    break;
                }
            }

            return [
                'healthy' => $ok,
                'latency_ms' => round($duration * 1000, 2),
                'details' => $ok ? 'Connected' : 'Ping failed',
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkExternalAPIs(): array
    {
        // max_redirects=0: a 3xx (e.g. MAL throttling -> error.json) is a
        // reachable-but-degraded signal, not an outage; don't chase redirects.
        $httpClient = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);

        $endpoints = [
            'anilist' => [
                'method' => 'POST',
                'url' => 'https://graphql.anilist.co',
                'body' => '{"query":"{ Page(page: 1, perPage: 1) { media(type: ANIME) { id } } }"}',
                'headers' => ['Content-Type' => 'application/json'],
            ],
            'kitsu' => [
                'method' => 'GET',
                'url' => 'https://kitsu.io/api/edge/anime?page[limit]=1',
            ],
            'myanimelist' => [
                'method' => 'GET',
                'url' => 'https://api.myanimelist.net/v2/anime/1?fields=id',
                'headers' => ['X-MAL-CLIENT-ID' => $_ENV['MAL_CLIENT_ID'] ?? ''],
            ],
        ];

        // Start every probe before reading any of them: the curl transport runs
        // them in parallel, so the whole check costs one round trip instead of
        // the sum of three. Reading the bodies afterwards is what waits.
        $pending = [];
        $startedAt = microtime(true);
        foreach ($endpoints as $name => $config) {
            $options = [];
            if (!empty($config['headers'])) {
                $options['headers'] = $config['headers'];
            }
            if (!empty($config['body'])) {
                $options['body'] = $config['body'];
            }
            $pending[$name] = $httpClient->request($config['method'], $config['url'], $options);
        }

        $results = [];
        $allHealthy = true;
        $allReachable = true;

        foreach ($pending as $name => $response) {
            try {
                $status = $response->getStatusCode();
                $info = $response->getInfo();
                // curl reports per-transfer time; fall back to wall clock for
                // other transports (and for probes that resolved immediately).
                $duration = (float) ($info['total_time'] ?? (microtime(true) - $startedAt));

                // Reachable = the API answered at all. Degraded-but-reachable
                // (rate limited 429, 404, 5xx) is NOT the same as unreachable.
                $reachable = true;
                $healthy = $status >= 200 && $status < 300;
                $note = null;
                if (!$healthy) {
                    $allHealthy = false;
                    $note = $status === 429 ? 'rate_limited' : ($status === 404 ? 'not_found' : 'http_' . $status);
                }

                $results[$name] = [
                    'healthy' => $healthy,
                    'reachable' => $reachable,
                    'latency_ms' => round($duration * 1000, 2),
                    'status_code' => $status,
                    'note' => $note,
                ];
            } catch (TransportExceptionInterface $e) {
                $allHealthy = false;
                $allReachable = false;
                $results[$name] = [
                    'healthy' => false,
                    'reachable' => false,
                    'error' => 'Network error: ' . $e->getMessage(),
                    'note' => 'unreachable',
                ];
            } catch (\Throwable $e) {
                $allHealthy = false;
                $allReachable = false;
                $results[$name] = [
                    'healthy' => false,
                    'reachable' => false,
                    'error' => $e->getMessage(),
                    'note' => 'unreachable',
                ];
            }
        }

        return [
            'healthy' => $allHealthy,
            'all_reachable' => $allReachable,
            'endpoints' => $results,
        ];
    }

    private function checkRateLimiter(): array
    {
        $stats = [];
        $healthy = true;

        $keys = ['anilist', 'kitsu', 'myanimelist', 'anilist_manga', 'kitsu_manga', 'myanimelist_manga'];
        foreach ($keys as $key) {
            // getStats() answers "closed / full tokens" for a key it has never
            // seen. Reporting that made the breaker panel render three healthy
            // rows forever, and made the circuit_open alert unreachable. An
            // unobserved limiter is now simply absent, and the dashboard shows
            // its "no limiter state reported yet" message.
            if (!$this->rateLimiter->hasObserved($key)) {
                continue;
            }

            $stat = $this->rateLimiter->getStats($key);
            $stats[$key] = $stat;

            if (($stat['circuit_state'] ?? 'closed') === 'open') {
                $healthy = false;
            }
        }

        return [
            'healthy' => $healthy,
            'limiters' => $stats,
        ];
    }

    private function checkDiskSpace(): array
    {
        $free = @disk_free_space('/');
        $total = @disk_total_space('/');
        // Both return false when the path is unreadable (open_basedir, a missing
        // mount), which is 0 in the arithmetic: `($total - $free) / $total`
        // threw DivisionByZeroError and 500'd both /api/status and /api/health
        // instead of reporting an unhealthy disk.
        if (!is_float($free) || !is_float($total) || $total <= 0) {
            return [
                'healthy' => false,
                'free_gb' => 0.0,
                'total_gb' => 0.0,
                'used_percent' => 0.0,
                'error' => 'disk space unavailable',
            ];
        }
        // A fresh overlayfs can report more free space than its quota claims;
        // clamp so the gauge cannot render a negative percentage.
        $usedPercent = max(0.0, (($total - $free) / $total) * 100);

        return [
            'healthy' => $usedPercent < 90,
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_percent' => round($usedPercent, 1),
        ];
    }

    /**
     * Host memory, matching checkDiskSpace()'s host-level scope.
     *
     * This used to report PHP's own per-request footprint against
     * `memory_limit`, which for the dashboard is ~1MB of a 512MB limit — always
     * 0% once rounded, so the Memory gauge rendered as an empty ring. The host's
     * pressure is what a health gauge should show; PHP's own numbers stay
     * available under `php_*` for diagnostics.
     */
    private function checkMemory(): array
    {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = ini_get('memory_limit');

        $host = $this->hostMemory();
        if ($host === null) {
            // No /proc (e.g. macOS): fall back to PHP's own budget.
            $limitBytes = $this->parseMemoryLimit($limit);
            $usedPercent = $limitBytes > 0 ? ($usage / $limitBytes) * 100 : 0;

            return [
                'healthy' => $usedPercent < 85,
                'source' => 'php',
                'used_percent' => round($usedPercent, 1),
                'current_mb' => round($usage / 1024 / 1024, 2),
                'peak_mb' => round($peak / 1024 / 1024, 2),
                'limit' => $limit,
            ];
        }

        return [
            'healthy' => $host['used_percent'] < 90,
            'source' => 'host',
            'used_percent' => round($host['used_percent'], 1),
            'total_gb' => round($host['total'] / 1024 / 1024 / 1024, 2),
            'available_gb' => round($host['available'] / 1024 / 1024 / 1024, 2),
            'php_current_mb' => round($usage / 1024 / 1024, 2),
            'php_peak_mb' => round($peak / 1024 / 1024, 2),
            'limit' => $limit,
        ];
    }

    /**
     * Read host memory from /proc/meminfo.
     *
     * `MemAvailable` already discounts reclaimable page cache, so the derived
     * percentage reflects real pressure rather than "everything is cache".
     *
     * @return array{total: float, available: float, used_percent: float}|null
     */
    private function hostMemory(): ?array
    {
        if (!is_readable('/proc/meminfo')) {
            return null;
        }
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return null;
        }

        $kb = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $kb[$m[1]] = (float) $m[2];
            }
        }

        $total = $kb['MemTotal'] ?? 0.0;
        if ($total <= 0) {
            return null;
        }
        $available = $kb['MemAvailable'] ?? $kb['MemFree'] ?? 0.0;

        return [
            'total' => $total * 1024,
            'available' => $available * 1024,
            'used_percent' => (($total - $available) / $total) * 100,
        ];
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;

        switch ($last) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return $value;
        }
    }
}
