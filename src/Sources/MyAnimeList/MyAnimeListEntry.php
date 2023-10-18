<?php

namespace Hiyori\Sources\MyAnimeList;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class MyAnimeListEntry extends \Hiyori\Sources\Entry
{
    const ENTRYPOINT = 'https://api.jikan.moe/v4/anime/%d/full';
    public static function create(&$id): self
    {
        $self = new self;

        $response = (new RetryableHttpClient(HttpClient::create()))
            ->request('GET', sprintf(self::ENTRYPOINT, $id))
            ->toArray();

        $self->setData($response['data']);

        return $self;
    }
}