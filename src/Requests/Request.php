<?php

namespace Hiyori\Requests;

use Symfony\Component\HttpClient\CachingHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpKernel\HttpCache\Store;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Request
{
    public const GET = 'GET';

    public const POST = 'POST';

    public static function fetch(string $url, string $method = self::GET, ?array $options = []): ResponseInterface
    {
        $telemetry = new \Hiyori\Service\Telemetry();
        $telemetry->update('cache', [
            'requests' => (int) ($telemetry->read('cache')['requests'] ?? 0) + 1,
            'enabled' => (bool) ($_ENV['HTTP_CACHE'] ?? false),
            'host' => (string) parse_url($url, PHP_URL_HOST),
        ]);

        $start = microtime(true);
        $response = self::doFetch($url, $method, $options);
        $ms = (microtime(true) - $start) * 1000;
        if ($ms >= 2000) {
            $telemetry->append('slow-requests', [
                'url' => (string) parse_url($url, PHP_URL_HOST) . (string) parse_url($url, PHP_URL_PATH),
                'ms' => (int) $ms,
                'method' => $method,
            ], 500);
        }

        return $response;
    }

    private static function doFetch(string $url, string $method = self::GET, ?array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $client = new RetryableHttpClient(HttpClient::create());

        if (!boolval($_ENV['HTTP_CACHE'] ?? false)) {
            return $client
                ->request(
                    $method,
                    $url,
                    (new HttpOptions())
                        ->setJson($options)
                        ->toArray(),
                );
        }

        $cachePath = $_ENV['HTTP_CACHE_PATH'] ?? '';
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
        $ttl = (int) ($_ENV['HTTP_CACHE_TTL'] ?? (60 * 60 * 24));

        return (new CachingHttpClient(
            $client,
            new Store(__DIR__ . '/../..' . $cachePath),
            [
                'debug' => $debug,
                'default_ttl' => $ttl,
            ],
        ))
            ->request(
                $method,
                $url,
                (new HttpOptions())
                    ->setJson($options)
                    ->toArray(),
            );
    }
}
