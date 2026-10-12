<?php

namespace Hiyori\Requests\AniList;

use Hiyori\Requests\EntryList;
use Hiyori\Requests\Request;

class AniListMangaEntryList extends EntryList
{
    public const ENTRYPOINT = 'https://graphql.anilist.co';

    public static function create(&$currentPage, ?int $startDateGreater = null, ?int $startDateLesser = null): self
    {
        $self = new self();

        // FuzzyDateInt values coerce incorrectly as GraphQL variables (AniList
        // quirk) — embed year-window bounds as inline literals instead.
        $filter = '';
        if ($startDateGreater !== null && $startDateLesser !== null) {
            $filter = sprintf(', startDate_greater: %d, startDate_lesser: %d', $startDateGreater, $startDateLesser);
        } elseif ($startDateGreater !== null) {
            $filter = sprintf(', startDate_greater: %d', $startDateGreater);
        } elseif ($startDateLesser !== null) {
            $filter = sprintf(', startDate_lesser: %d', $startDateLesser);
        }

        $query = str_replace('__STARTDATE__', $filter, (string) file_get_contents(__DIR__ . '/Data/MangaEntryListRequest.graphql'));

        $response = Request::fetch(
            self::ENTRYPOINT,
            Request::POST,
            [
                'query' => $query,
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
