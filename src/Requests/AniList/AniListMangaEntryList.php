<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;

class AniListMangaEntryList extends EntryList
{
    public const ENTRYPOINT = 'https://graphql.anilist.co';

    public static function create(&$currentPage): self
    {
        $self = new self();

        $response = Request::fetch(
            self::ENTRYPOINT,
            Request::POST,
            [
                'query' => file_get_contents(__DIR__ . '/Data/MangaEntryListRequest.graphql'),
                'variables' => [
                    'page' => $currentPage,
                    'type' => 'MANGA',
                ],
            ],
        )
            ->toArray();

        $self->setList($response['data']['Page']['media'] ?? []);

        return $self;
    }
}
