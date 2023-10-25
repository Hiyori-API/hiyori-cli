<?php

namespace Hiyori\Models\Anime\Base;

use Hiyori\Helper;
use Hiyori\Models\Anime\Season;
use Hiyori\Models\Anime\Status;
use Hiyori\Models\Anime\Type;
use Hiyori\Models\Common\AniListTitle;
use Hiyori\Models\Common\Base;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Common\Identifiers\MyAnimeList;


class AniListBase extends Base
{
    public static function create(array $json): self
    {
        $self = new self;

        $titles = $json['title'] ?? [];
        $synonyms = $json['synonyms'] ?? [];
        $titles = new AniListTitle($json['title']['romaji'], array_merge($titles, $synonyms));
        $self->title = $titles->getDefault();
        $self->synonyms = $titles->getSynonyms();

        $self->type = $json['format'] === null ? null : Type::fromString(Helper::prepareAsIdentifer($json['format']))->value;
        $self->episodes = $json['episodes'];
        $self->status = $json['status'] === null ? null : Status::fromString(Helper::prepareAsIdentifer($json['status']))->value;

        $self->season = $json['season'] === null ? null : Season::fromString(Helper::prepareAsIdentifer($json['season']))->value;
        $self->year = $json['seasonYear'] ?? null;

        $self->images[] = $json['coverImage']['large'];

        $self->references[] = $json['siteUrl'];

        // Anilist has MAL ID as a separate property
        // it's not returned in externalLinks
        if ($json['idMal'] !== null) {
            $self->references[] = sprintf(MyAnimeList::ENTRY_URL, $json['idMal']);
        }

        foreach ($json['externalLinks'] ?? [] as $link) {
            $self->references[] = $link['url'];
        }

        $self->referenceIds = (new Identifiers($self->references))->getModel();

        foreach ($json['tags'] ?? [] as $tag) {
            $isGeneralSpoiler = $tag['isGeneralSpoiler'] === true;
            $isMediaSpoiler = $tag['isMediaSpoiler'] === true;

            if ($isGeneralSpoiler || $isMediaSpoiler) {
                continue;
            }

            $self->tags[] = Helper::prepareAsIdentifer($tag['name']);
        }

        return $self;
    }
}