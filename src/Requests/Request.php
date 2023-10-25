<?php

namespace Hiyori\Requests;

use Hiyori\Requests\Entry;
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
    public static function fetch($method = self::GET, string $url, ?array $options = []): ResponseInterface
    {
        $client = new RetryableHttpClient(HttpClient::create());

        if (!boolval($_ENV['HTTP_CACHE'])) {
            return $client
                ->request(
                    $method,
                    $url,
                    (new HttpOptions())
                        ->setJson($options)
                        ->toArray()
                );
        }

        return (new CachingHttpClient(
            $client,
            new Store(__DIR__.'/../..'.$_ENV['HTTP_CACHE_PATH']),
            [
                'debug' => (bool) $_ENV['APP_DEBUG'] ?? false,
                'default_ttl' => (int) $_ENV['HTTP_CACHE_TTL'] ?? (60*60*24)
            ],
        ))
            ->request(
                $method,
                $url,
                (new HttpOptions())
                    ->setJson($options)
                    ->toArray()
            );
    }
}