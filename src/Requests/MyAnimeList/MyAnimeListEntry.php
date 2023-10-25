<?php

namespace Hiyori\Requests\MyAnimeList;

use Hiyori\Requests\Entry;
use Hiyori\Requests\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class MyAnimeListEntry extends Entry
{
    const ENTRYPOINT = 'https://api.jikan.moe/v4/anime/%d/full';
    public static function create(&$id): self
    {
        $self = new self;

        $response = Request::fetch(
            Request::GET,
            sprintf(self::ENTRYPOINT, $id)
        )->toArray();

        $self->setData($response['data']);

        return $self;
    }
}