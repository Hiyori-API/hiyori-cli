<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\Entry;
use Hiyori\Requests\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

class KitsuEntryCategories extends Entry
{
    const ENTRYPOINT = 'https://kitsu.io/api/edge/anime/%d/categories';
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