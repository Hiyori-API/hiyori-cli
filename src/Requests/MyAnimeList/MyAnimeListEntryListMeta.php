<?php

namespace Hiyori\Requests\MyAnimeList;

use Hiyori\Requests\EntryListMeta;
use Hiyori\Requests\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class MyAnimeListEntryListMeta extends EntryListMeta
{
    const ENTRYPOINT = 'https://api.jikan.moe/v4/anime';
    public static function create(): self
    {
        $self = new self;

        $response = Request::fetch(
            Request::GET,
            $self::ENTRYPOINT
        )->toArray();

        $self->setLastPage($response['pagination']['last_visible_page']);
        $self->setTotalEntries($response['pagination']['items']['total']);

        return $self;
    }
}