<?php

namespace Hiyori\Sources\Kitsu\Requests;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryMappings extends \Hiyori\Sources\Entry
{
    const ENTRYPOINT = 'https://kitsu.io/api/edge/anime/%d/mappings';
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