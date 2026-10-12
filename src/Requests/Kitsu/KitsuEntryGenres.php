<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\Entry;
use Hiyori\Requests\Request;

class KitsuEntryGenres extends Entry
{
    public const ENTRYPOINT = 'https://kitsu.io/api/edge/anime/%d/genres';

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
