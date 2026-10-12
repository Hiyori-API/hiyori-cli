<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;

class AniListEntryList extends EntryList
{
    public const ENTRYPOINT = 'https://graphql.anilist.co';

    public static function create(&$currentPage, ?int $seasonYear = null): self
    {
        $self = new self();

        $response = Request::fetch(
            self::ENTRYPOINT,
            Request::POST,
            [
                'query' => file_get_contents(__DIR__ . '/Data/EntryListRequest.graphql'),
                'variables' => array_filter([
                    'page' => $currentPage,
                    'type' => 'ANIME',
                    'seasonYear' => $seasonYear,
                ], static fn ($v) => $v !== null),
            ],
        )
            ->toArray();

        $self->setList($response['data']['Page']['media'] ?? []);

        return $self;
    }
}
