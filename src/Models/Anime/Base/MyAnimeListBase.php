<?php

namespace Hiyori\Models\Anime\Base;

use Hiyori\Helper;
use Hiyori\Models\Anime\Season;
use Hiyori\Models\Anime\Status;
use Hiyori\Models\Anime\Type;
use Hiyori\Models\Common\Base;
use Hiyori\Models\Common\Identifiers\Identifiers;

class MyAnimeListBase extends Base
{
    public static function create(array $json): self
    {
        $self = new self();

        // Handle official API response format
        $title = $json['title'] ?? '';
        $altTitles = $json['alternative_titles'] ?? [];
        $synonyms = [];
        if (!empty($altTitles['synonyms'])) {
            $synonyms = array_merge($synonyms, $altTitles['synonyms']);
        }
        if (!empty($altTitles['en'])) {
            $synonyms[] = $altTitles['en'];
        }
        if (!empty($altTitles['ja'])) {
            $synonyms[] = $altTitles['ja'];
        }
        $synonyms = array_unique($synonyms, SORT_STRING);

        $self->title = $title;
        $self->synonyms = $synonyms;

        $type = $json['media_type'] ?? null;
        $self->type = $type === null ? null : Type::fromString(Helper::prepareAsIdentifer($type))->value;

        $self->episodes = $json['num_episodes'] ?? null;

        $status = $json['status'] ?? null;
        $self->status = $status === null ? null : Status::fromString(Helper::prepareAsIdentifer($status))->value;

        $season = $json['start_season']['season'] ?? null;
        $self->season = $season === null ? null : Season::fromString(Helper::prepareAsIdentifer($season))->value;

        $self->year = $json['start_season']['year'] ?? null;

        // Images
        if (!empty($json['main_picture']['large'])) {
            $self->images[] = $json['main_picture']['large'];
        } elseif (!empty($json['main_picture']['medium'])) {
            $self->images[] = $json['main_picture']['medium'];
        }

        // References
        $self->references[] = "https://myanimelist.net/anime/{$json['id']}";

        // Alternative titles as references
        if (!empty($altTitles['en'])) {
            $self->references[] = "https://myanimelist.net/anime/{$json['id']}/" . urlencode($altTitles['en']);
        }

        $self->referenceIds = (new Identifiers($self->references))->getModel();

        // Genres/Tags
        foreach ($json['genres'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre['name']);
        }
        foreach ($json['explicit_genres'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre['name']);
        }
        foreach ($json['themes'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre['name']);
        }
        foreach ($json['demographics'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre['name']);
        }
        $self->tags = array_unique($self->tags, SORT_STRING);

        return $self;
    }
}
