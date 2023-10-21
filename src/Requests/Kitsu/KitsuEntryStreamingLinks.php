<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\Entry;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryStreamingLinks extends Entry
{
    const ENTRYPOINT = 'https://kitsu.io/api/edge/anime/%d/streaming-links';
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