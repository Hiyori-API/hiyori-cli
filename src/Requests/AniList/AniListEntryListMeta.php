<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryListMeta;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpClient\RetryableHttpClient;

class AniListEntryListMeta extends EntryListMeta
{
    const ENTRYPOINT = 'https://graphql.anilist.co';
    public static function create(&$currentPage = 1): self
    {
        $self = new self;

        $response = (new RetryableHttpClient(HttpClient::create()))
            ->request('POST', self::ENTRYPOINT,
                (new HttpOptions())
                    ->setJson(
                        [
                            'query' => file_get_contents(__DIR__ . '/Data/EntryListRequest.graphql'),
                            'variables' => [
                                'page' => $currentPage,
                                'type' => 'ANIME'
                            ]
                        ]
                    )->toArray()
            )
            ->toArray();

        $self->setLastPage($response['data']['Page']['pageInfo']['lastPage']);
        $self->setTotalEntries($response['data']['Page']['pageInfo']['total']);

        return $self;
    }
}