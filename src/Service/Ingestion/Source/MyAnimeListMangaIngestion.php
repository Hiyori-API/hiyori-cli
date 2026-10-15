<?php

namespace Hiyori\Service\Ingestion\Source;

use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\Telemetry;
use Symfony\Component\HttpClient\HttpClient;

final class MyAnimeListMangaIngestion
{
    public static function process(
        Database $db,
        ConsoleFactory $console,
        SourceConfigurationFactory $sources,
        RateLimiter $rateLimiter
    ): void {
        $config = $sources->get();
        $clientId = $_ENV['MAL_CLIENT_ID'] ?? null;
        $sourceKey = 'myanimelist_manga';

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

        // MAL's ranking endpoint does not expose a total; run to an empty page.
        $meta = self::fetchMeta($httpClient, $console, $rateLimiter, $sourceKey);
        if (!$meta) {
            return;
        }

        $total = max(0, (int) ($meta['total'] ?? 0)); // 0 = unknown
        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1);
        $progressBar->setFormat($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%');
        $progressBar->start();

        $processed = 0;
        $saved = 0;
        $skipped = 0;
        $errors = 0;
        Telemetry::track($sourceKey, 0, $total, 0, 'running');

        $offset = 0;
        $limit = 500;
        $maxPages = max(0, (int) ($_ENV['MAL_MAX_PAGES'] ?? 0)); // 0 = no ceiling
        $page = 0;
        $consecutivePageErrors = 0;

        while ($maxPages === 0 || $page < $maxPages) {
            $entries = self::fetchPage($httpClient, $offset, $limit, $console, $rateLimiter, $sourceKey);
            if ($entries === null) {
                $consecutivePageErrors++;
                if ($consecutivePageErrors >= 3) {
                    $console->io()->warning('MAL ranking page failed 3 times in a row — stopping to avoid a retry loop.');
                    break;
                }
                continue;
            }
            $consecutivePageErrors = 0;
            if ($entries === []) {
                break;
            }

            foreach ($entries as $entry) {
                $itemId = $entry['node']['id'];
                $processed++;

                if ($db->exists($config->getTable(), $config->getShorthand(), $itemId)) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                $rateLimiter->waitForToken($sourceKey, 1, 60);
                sleep($sources->get()->getDelay());

                try {
                    $detail = self::fetchDetail($httpClient, $itemId, $console, $rateLimiter, $sourceKey);
                    if ($detail) {
                        $db->save($config->getTable(), \Hiyori\Models\Manga\Base\MyAnimeListBase::create($detail));
                        $saved++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $console->io()->error("[$itemId] " . $e->getMessage());
                }

                if ($processed % 25 === 0) {
                    Telemetry::track($sourceKey, $processed, $total, $errors, 'running', 'initial', [
                        'saved' => $saved,
                        'skipped' => $skipped,
                    ]);
                }
                $progressBar->advance();
            }

            $offset += $limit;
            $page++;
        }

        Telemetry::track($sourceKey, $processed, $total, $errors, 'done', 'initial', [
            'saved' => $saved,
            'skipped' => $skipped,
        ]);
        $progressBar->finish();
        $console->io()->info("Ingestion complete for {$config->getName()} — saved: $saved, skipped: $skipped, errors: $errors");
    }

    private static function fetchMeta($httpClient, $console, RateLimiter $rateLimiter, string $sourceKey): ?array
    {
        try {
            $response = $rateLimiter->executeWithRetry(
                fn () => $httpClient->request('GET', 'manga/ranking', [
                    'query' => ['ranking_type' => 'all', 'limit' => 1],
                ]),
                'myanimelist',
                3,
                1000,
                2.0,
            );

            return ['total' => null]; // MAL ranking does not expose a total.
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>|null null on transport error, [] when the ranking is exhausted
     */
    private static function fetchPage($httpClient, int $offset, int $limit, $console, RateLimiter $rateLimiter, string $sourceKey): ?array
    {
        try {
            $response = $rateLimiter->executeWithRetry(
                fn () => $httpClient->request('GET', 'manga/ranking', [
                    'query' => [
                        'ranking_type' => 'all',
                        'offset' => $offset,
                        'limit' => min($limit, 500),
                    ],
                ]),
                'myanimelist',
                3,
                1000,
                2.0,
            );

            return $response->toArray()['data'] ?? [];
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function fetchDetail($httpClient, int $id, $console, RateLimiter $rateLimiter, string $sourceKey): ?array
    {
        try {
            $response = $rateLimiter->executeWithRetry(
                fn () => $httpClient->request('GET', "manga/$id", [
                    'query' => ['fields' => 'id,title,main_picture,alternative_titles,start_date,end_date,synopsis,mean,rank,popularity,num_list_users,num_scoring_users,nsfw,created_at,updated_at,media_type,status,genres,num_chapters,num_volumes,start_date,broadcast,source,average_episode_duration,rating,pictures,background,related_anime,related_manga,recommendations,studios,statistics'],
                ]),
                'myanimelist',
                3,
                1000,
                2.0,
            );

            return $response->toArray();
        } catch (\Exception $e) {
            return null;
        }
    }
}
