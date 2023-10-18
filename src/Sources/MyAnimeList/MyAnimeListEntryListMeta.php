<?php

namespace Hiyori\Sources\MyAnimeList;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class MyAnimeListEntryListMeta extends \Hiyori\Sources\EntryListMeta
{
    const ENTRYPOINT = 'https://api.jikan.moe/v4/anime';
    public static function create(): self
    {
        $self = new self;

        $response = (new RetryableHttpClient(HttpClient::create()))
            ->request('GET', self::ENTRYPOINT)
            ->toArray();

        $self->setLastPage($response['pagination']['last_visible_page']);
        $self->setTotalEntries($response['pagination']['items']['total']);

        return $self;
    }
}