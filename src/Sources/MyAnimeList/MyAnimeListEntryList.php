<?php

namespace Hiyori\Sources\MyAnimeList;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class MyAnimeListEntryList extends \Hiyori\Sources\EntryList
{
    const ENTRYPOINT = 'https://api.jikan.moe/v4/anime?page=%d';
    public static function create(&$currentPage): self
    {
        $self = new self;

        $response = (new RetryableHttpClient(HttpClient::create()))
            ->request('GET', sprintf(self::ENTRYPOINT, $currentPage))
            ->toArray();

        $self->setList($response['data']);

        return $self;
    }
}