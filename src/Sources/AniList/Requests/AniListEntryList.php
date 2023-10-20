<?php

namespace Hiyori\Sources\AniList\Requests;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpClient\RetryableHttpClient;

class AniListEntryList extends \Hiyori\Sources\EntryList
{
    const ENTRYPOINT = 'https://graphql.anilist.co';
    public static function create(&$currentPage): self
    {
        $self = new self;

        $response = (new RetryableHttpClient(HttpClient::create()))
            ->request('POST', self::ENTRYPOINT,
                (new HttpOptions())
                    ->setJson(
                        [
                            'query' => file_get_contents(__DIR__.'/Data/EntryListRequest.graphql'),
                            'variables' => [
                                'page' => $currentPage,
                                'type' => 'ANIME',
                                'sort' => 'id'
                            ]
                        ]
                    )->toArray()
            )
            ->toArray();

        $self->setList($response['data']['Page']['ANIME']);

        return $self;
    }
}