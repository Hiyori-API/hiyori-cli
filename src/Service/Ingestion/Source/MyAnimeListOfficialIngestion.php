<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Models\Anime\Base\MyAnimeListBase;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

final class MyAnimeListOfficialIngestion
{
    private const BASE_URL = 'https://api.myanimelist.net/v2/'; // trailing slash required
    private const FIELDS = 'id,title,main_picture,alternative_titles,start_date,end_date,synopsis,mean,rank,popularity,num_list_users,num_scoring_users,nsfw,created_at,updated_at,media_type,status,genres,num_episodes,start_season,broadcast,source,average_episode_duration,rating,pictures,background,related_anime,related_manga,recommendations,studios,statistics';

    public static function process(
        Database $db, ConsoleFactory $console, SourceConfigurationFactory $sources
    ): void {
        $config = $sources->get();
        $clientId = $_ENV['MAL_CLIENT_ID'] ?? null;
        
        if (!$clientId) {
            $console->io()->error('MAL_CLIENT_ID not set in .env. Register at https://myanimelist.net/apiconfig');
            return;
        }

        $httpClient = HttpClient::create([
            'base_uri' => self::BASE_URL,
            'headers' => [
                'X-MAL-CLIENT-ID' => $clientId,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        $httpClient = new RetryableHttpClient($httpClient, new GenericRetryStrategy(), 3);

        // Get total count from ranking
        $meta = self::fetchMeta($httpClient, $console);
        if (!$meta) return;

        $progressBar = $console->io()->createProgressBar($meta['total']);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? "%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%");
        $progressBar->start();

        $offset = 0;
        $limit = 500; // Max limit for ranking endpoint
        
        while ($offset < $meta['total']) {
            $entries = self::fetchPage($httpClient, $offset, $limit, $console);
            if (empty($entries)) break;

            foreach ($entries as $entry) {
                $itemId = $entry['node']['id'];
                
                if ($db->exists($config->getTable(), $config->getShorthand(), $itemId)) {
                    $progressBar->advance();
                    continue;
                }

                sleep($sources->get()->getDelay());

                try {
                    $detail = self::fetchDetail($httpClient, $itemId, $console);
                    if ($detail) {
                        $db->save($config->getTable(), MyAnimeListBase::create($detail));
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

    private static function fetchMeta($httpClient, $console): ?array
    {
        try {
            $response = $httpClient->request('GET', 'anime/ranking', [
                'query' => ['ranking_type' => 'all', 'limit' => 1],
            ]);
            $data = $response->toArray();
            return ['total' => 25000]; // Approximate total anime on MAL
        } catch (\Exception $e) {
            $console->io()->error("Failed to fetch meta: " . $e->getMessage());
            return null;
        }
    }

    private static function fetchPage($httpClient, int $offset, int $limit, $console): array
    {
        try {
            $response = $httpClient->request('GET', 'anime/ranking', [
                'query' => [
                    'ranking_type' => 'all',
                    'offset' => $offset,
                    'limit' => min($limit, 500),
                ],
            ]);
            return $response->toArray()['data'] ?? [];
        } catch (\Exception $e) {
            $console->io()->error("Failed to fetch page at offset $offset: " . $e->getMessage());
            return [];
        }
    }

    private static function fetchDetail($httpClient, int $id, $console): ?array
    {
        try {
            $response = $httpClient->request('GET', "anime/$id", [
                'query' => ['fields' => self::FIELDS],
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            $console->io()->error("Failed to fetch detail for $id: " . $e->getMessage());
            return null;
        }
    }
}
