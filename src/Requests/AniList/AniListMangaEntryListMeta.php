<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryListMeta;
use Hiyori\Requests\Request;

class AniListMangaEntryListMeta extends EntryListMeta
{
    public const ENTRYPOINT = 'https://graphql.anilist.co';

    private bool $hasNextPage = false;

    public static function create(&$currentPage = 1, ?int $startDateGreater = null, ?int $startDateLesser = null): self
    {
        $self = new self();

        $response = Request::fetch(
            self::ENTRYPOINT,
            Request::POST,
            [
                'query' => file_get_contents(__DIR__ . '/Data/MangaEntryListRequest.graphql'),
                'variables' => array_filter([
                    'page' => $currentPage,
                    'type' => 'MANGA',
                    'startDateGreater' => $startDateGreater,
                    'startDateLesser' => $startDateLesser,
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

    public function setHasNextPage(bool $hasNextPage): AniListMangaEntryListMeta
    {
        $this->hasNextPage = $hasNextPage;

        return $this;
    }
}
