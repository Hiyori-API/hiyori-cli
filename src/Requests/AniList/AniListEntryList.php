<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;

class AniListEntryList extends EntryList
{
    public const ENTRYPOINT = 'https://graphql.anilist.co';

    public static function create(&$currentPage): self
    {
        $self = new self();

        $response = Request::fetch(
            self::ENTRYPOINT,
            Request::POST,
            [
                'query' => file_get_contents(__DIR__ . '/Data/EntryListRequest.graphql'),
            'variables' => [
                    'page' => $currentPage,
            'type' => 'ANIME',
            ],
            ],
        )
            ->toArray();

        $self->setList($response['data']['Page']['ANIME']);

        return $self;
    }
}
