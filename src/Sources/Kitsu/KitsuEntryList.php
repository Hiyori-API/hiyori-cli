<?php

namespace Hiyori\Sources\Kitsu;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryList extends \Hiyori\Sources\EntryList
{
    const ENTRYPOINT = 'https://kitsu.io/api/edge/anime?page[limit]=20&page[offset]=%d';
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