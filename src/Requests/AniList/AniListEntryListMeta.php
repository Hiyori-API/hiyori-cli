<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryListMeta;
use Hiyori\Requests\Request;

class AniListEntryListMeta extends EntryListMeta
{
    const ENTRYPOINT = 'https://graphql.anilist.co';

    private bool $hasNextPage = false;
    public static function create(&$currentPage = 1): self
    {
        $self = new self;

        $response = Request::fetch(
            Request::POST,
            self::ENTRYPOINT,
            [
                'query' => file_get_contents(__DIR__ . '/Data/EntryListRequest.graphql'),
                'variables' => [
                    'page' => $currentPage,
                    'type' => 'ANIME',
                ]
            ]
        )->toArray();

        $self->setLastPage($response['data']['Page']['pageInfo']['lastPage']);
        $self->setTotalEntries($response['data']['Page']['pageInfo']['total']);
        $self->setHasNextPage($response['data']['Page']['pageInfo']['hasNextPage']);

        return $self;
    }

    /**
     * @return bool
     */
    public function hasNextPage(): bool
    {
        return $this->hasNextPage;
    }

    /**
     * @param bool $hasNextPage
     * @return AniListEntryListMeta
     */
    public function setHasNextPage(bool $hasNextPage): AniListEntryListMeta
    {
        $this->hasNextPage = $hasNextPage;
        return $this;
    }
}