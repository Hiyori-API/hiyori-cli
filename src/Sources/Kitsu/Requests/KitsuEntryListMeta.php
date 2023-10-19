<?php

namespace Hiyori\Sources\Kitsu\Requests;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryListMeta extends \Hiyori\Sources\EntryListMeta
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