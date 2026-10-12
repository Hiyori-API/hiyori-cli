<?php

namespace Hiyori\Requests\MyAnimeList;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;

class MyAnimeListEntryList extends EntryList
{
    public const ENTRYPOINT = 'https://api.jikan.moe/v4/anime?page=%d';

    public static function create(&$currentPage): self
    {
        $self = new self();

        $response = Request::fetch(
            sprintf(self::ENTRYPOINT, $currentPage),
            Request::GET,
        )
            ->toArray();

        $self->setList($response['data']);

        return $self;
    }
}
