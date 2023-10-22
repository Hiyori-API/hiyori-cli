<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\EntryListMeta;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryListMeta extends EntryListMeta
{
    const ENTRYPOINT = 'https://kitsu.io/api/edge/anime?page[limit]=20&page[offset]=0';
    public static function create(): self
    {
        $self = new self;

        $response = (new RetryableHttpClient(HttpClient::create()))
            ->request('GET', self::ENTRYPOINT)
            ->toArray();

        $self->setLastPage($response['meta']['count']);
        $self->setTotalEntries($response['meta']['count']);

        return $self;
    }
}