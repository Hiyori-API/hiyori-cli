<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Manga\Base\MyAnimeListBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

final class MyAnimeListMangaIngestion
{
    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources, RateLimiter $rateLimiter
    ): void {
        $config = $sources->get();
        $clientId = $_ENV['MAL_CLIENT_ID'] ?? null;
        $sourceKey = 'myanimelist';
        
        if (!$clientId) {
            $console->io()->error('MAL_CLIENT_ID not set in .env. Register at https://myanimelist.net/apiconfig');
            return;
        }

        $httpClient = HttpClient::create([
            'base_uri' => 'https://api.myanimelist.net/v2/',
            'headers' => [
                'X-MAL-CLIENT-ID' => $clientId,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        $httpClient = new \Symfony\Component\HttpClient\RetryableHttpClient($httpClient, new \Symfony\Component\HttpClient\Retry\GenericRetryStrategy(), 3);

        $meta = self::fetchMeta($httpClient, $console, $rateLimiter, $sourceKey);
        if (!$meta) return;

        $progressBar = $console->io()->createProgressBar($meta['total']);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        $offset = 0;
        $limit = 500;
        
        while ($offset < $meta['total']) {
            $entries = self::fetchPage($httpClient, $offset, $limit, $console, $rateLimiter, $sourceKey);
            if (empty($entries)) break;

            foreach ($entries as $entry) {
                $itemId = $entry['node']['id'];
                
                if ($db->exists($config->getTable(), $config->getShorthand(), $itemId)) {
                    $progressBar->advance();
                    continue;
                }

                $rateLimiter->waitForToken($sourceKey, 1, 60);
                sleep($sources->get()->getDelay());

                try {
                    $detail = self::fetchDetail($httpClient, $itemId, $console, $rateLimiter, $sourceKey);
                    if ($detail) {
                        $db->save($config->getTable(), \Hiyori\Models\Manga\Base\MyAnimeListBase::create($detail));
                    }
                } catch (\Exception $e) {
                    $console->io()->error("[$itemId] " . $e->getMessage());
                }

                $progressBar->advance();
            }

            $offset += $limit;
            sleep($sources->get()->getDelay());
        }

        $progressBar->finish();
        $console->io()->info("Ingestion complete for {$config->getName()}");
    }

    private static function fetchMeta($httpClient, $console, RateLimiter $rateLimiter, string $sourceKey): ?array
    {
        try {
            $response = $rateLimiter->executeWithRetry(
                fn() => $httpClient->request('GET', 'manga/ranking', [
                    'query' => ['ranking_type' => 'all', 'limit' => 1],
                ]),
                'myanimelist',
                3,
                1000,
                2.0
            );
            return ['total' => 25000];
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function fetchPage($httpClient, int $offset, int $limit, $console, RateLimiter $rateLimiter, string $sourceKey): array
    {
        try {
            $response = $rateLimiter->executeWithRetry(
                fn() => $httpClient->request('GET', 'manga/ranking', [
                    'query' => [
                        'ranking_type' => 'all',
                        'offset' => $offset,
                        'limit' => min($limit, 500),
                    ],
                ]),
                'myanimelist',
                3,
                1000,
                2.0
            );
            return $response->toArray()['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function fetchDetail($httpClient, int $id, $console, RateLimiter $rateLimiter, string $sourceKey): ?array
    {
        try {
            $response = $rateLimiter->executeWithRetry(
                fn() => $httpClient->request('GET', "manga/$id", [
                    'query' => ['fields' => 'id,title,main_picture,alternative_titles,start_date,end_date,synopsis,mean,rank,popularity,num_list_users,num_scoring_users,nsfw,created_at,updated_at,media_type,status,genres,num_chapters,num_volumes,start_date,broadcast,source,average_episode_duration,rating,pictures,background,related_anime,related_manga,recommendations,studios,statistics'],
                ]),
                'myanimelist',
                3,
                1000,
                2.0
            );
            return $response->toArray();
        } catch (\Exception $e) {
            return null;
        }
    }
}
