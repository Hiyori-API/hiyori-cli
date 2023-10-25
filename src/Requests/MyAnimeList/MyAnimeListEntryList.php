<?php

namespace Hiyori\Requests\MyAnimeList;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class MyAnimeListEntryList extends EntryList
{
    const ENTRYPOINT = 'https://api.jikan.moe/v4/anime?page=%d';
    public static function create(&$currentPage): self
    {
        $self = new self;

        $response = Request::fetch(
            Request::GET,
            sprintf(self::ENTRYPOINT, $currentPage)
        )->toArray();

        $self->setList($response['data']);

        return $self;
    }
}