<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;

class KitsuEntryList extends EntryList
{
    public const ENTRYPOINT = 'https://kitsu.io/api/edge/anime?page[limit]=20&page[offset]=%d';

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
