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
        $debug = (bool) ($_ENV['APP_DEBUG'] ?? false);
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
