<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryList extends EntryList
{
    const ENTRYPOINT = 'https://kitsu.io/api/edge/anime?page[limit]=20&page[offset]=%d';
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