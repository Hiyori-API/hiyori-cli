<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryListMeta;
use Hiyori\Requests\Request;

class AniListEntryListMeta extends EntryListMeta
{
    public const ENTRYPOINT = 'https://graphql.anilist.co';

    private bool $hasNextPage = false;

    public static function create(&$currentPage = 1, ?int $seasonYear = null): self
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

        $self->setLastPage($response['data']['Page']['pageInfo']['lastPage']);
        $self->setTotalEntries($response['data']['Page']['pageInfo']['total']);
        $self->setHasNextPage($response['data']['Page']['pageInfo']['hasNextPage']);

        return $self;
    }

    public function hasNextPage(): bool
    {
        return $this->hasNextPage;
    }

    public function setHasNextPage(bool $hasNextPage): AniListEntryListMeta
    {
        $this->hasNextPage = $hasNextPage;

        return $this;
    }
}
