<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\EntryListMeta;
use Hiyori\Requests\Request;

class KitsuEntryListMeta extends EntryListMeta
{
    public const ENTRYPOINT = 'https://kitsu.io/api/edge/anime?page[limit]=20&page[offset]=0';

    public static function create(): self
    {
        $self = new self();

        $response = Request::fetch(
            $self::ENTRYPOINT,
            Request::GET,
        )
            ->toArray();

        $self->setLastPage($response['meta']['count']);
        $self->setTotalEntries($response['meta']['count']);

        return $self;
    }
}
