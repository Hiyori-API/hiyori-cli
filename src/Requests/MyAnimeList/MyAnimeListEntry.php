<?php

namespace Hiyori\Requests\MyAnimeList;

use Hiyori\Requests\Entry;
use Hiyori\Requests\Request;

class MyAnimeListEntry extends Entry
{
    public const ENTRYPOINT = 'https://api.jikan.moe/v4/anime/%d/full';

    public static function create(&$id): self
    {
        $self = new self();

        $response = Request::fetch(
            sprintf(self::ENTRYPOINT, $id),
            Request::GET,
        )
            ->toArray();

        $self->setData($response['data']);

        return $self;
    }
}
