<?php

namespace Hiyori\Requests\MyAnimeList;

use Hiyori\Requests\EntryListMeta;
use Hiyori\Requests\Request;

class MyAnimeListEntryListMeta extends EntryListMeta
{
    public const ENTRYPOINT = 'https://api.jikan.moe/v4/anime';

    public static function create(): self
    {
        $self = new self();

        $response = Request::fetch(
            $self::ENTRYPOINT,
            Request::GET,
        )
            ->toArray();

        $self->setLastPage($response['pagination']['last_visible_page']);
        $self->setTotalEntries($response['pagination']['items']['total']);

        return $self;
    }
}
