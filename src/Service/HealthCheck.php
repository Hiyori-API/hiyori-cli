<?php

namespace Hiyori\Service;

use MongoDB\Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Exception\TransportExceptionInterface;

final class HealthCheck
{
    public function __construct(
        private Client $mongoClient,
        private MetricsCollector $metrics,
        private RateLimiter $rateLimiter
    ) {}

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
        $results = [];
        $allHealthy = true;
        
        $httpClient = HttpClient::create(['timeout' => 5]);
        
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
        
        $results = [];
        $allHealthy = true;
        
        foreach ($endpoints as $name => $config) {
            try {
                $start = microtime(true);
                
                $options = [];
                if (!empty($config['headers'])) {
                    $options['headers'] = $config['headers'];
                }
                if (!empty($config['body'])) {
                    $options['body'] = $config['body'];
                }
                
                $httpClient = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 5]);
                $response = $httpClient->request($config['method'], $config['url'], $options);
                $duration = microtime(true) - $start;
                $status = $response->getStatusCode();
                
                $healthy = $status >= 200 && $status < 300;
                if (!$healthy) $allHealthy = false;
                
                $results[$name] = [
                    'healthy' => $healthy,
                    'latency_ms' => round($duration * 1000, 2),
                    'status_code' => $status,
                ];
            } catch (\Symfony\Component\HttpClient\Exception\TransportExceptionInterface $e) {
                $allHealthy = false;
                $results[$name] = [
                    'healthy' => false,
                    'error' => 'Network error: ' . $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $allHealthy = false;
                $results[$name] = [
                    'healthy' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'healthy' => $allHealthy,
            'endpoints' => $results,
        ];
    }

    private function checkRateLimiter(): array
    {
        $stats = [];
        $healthy = true;
        
        $keys = ['anilist', 'kitsu', 'myanimelist'];
        foreach ($keys as $key) {
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
        $free = disk_free_space('/');
        $total = disk_total_space('/');
        $usedPercent = (($total - $free) / $total) * 100;
        
        return [
            'healthy' => $usedPercent < 90,
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_percent' => round($usedPercent, 1),
        ];
    }

    private function checkMemory(): array
    {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = ini_get('memory_limit');
        
        $limitBytes = $this->parseMemoryLimit($limit);
        $usedPercent = $limitBytes > 0 ? ($usage / $limitBytes) * 100 : 0;
        
        return [
            'healthy' => $usedPercent < 85,
            'current_mb' => round($usage / 1024 / 1024, 2),
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit' => $limit,
            'used_percent' => round($usedPercent, 1),
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
