<?php

namespace Hiyori\Models\Anime\Base;

use Hiyori\Models\Common\Base;

class AnimeBase extends Base
{
    public static function create(array $json): Base
    {
        $self = new self();

        $self->title = $json['title'];
        $self->synonyms = $json['synonyms'];
        $self->type = $json['type'];
        $self->episodes = $json['episodes'];
        $self->status = $json['status'];
        $self->season = $json['season'];
        $self->year = $json['year'];
        $self->images = $json['image'];
        $self->referenceIds = $json['reference_ids'];
        $self->references = $json['references'];
        $self->tags = $json['tags'];

        return $self;
    }

    public static function merge(array|object $entry, array|object $mergeEntry): Base
    {
        $self = new self();

        $self->title = $entry['title'] ?? $mergeEntry['title'];

        $synonyms = array_merge($entry['synonyms'] ?? [], $mergeEntry['synonyms'] ?? [], [$mergeEntry['title']]);
        $self->synonyms = array_unique($synonyms, SORT_STRING);

        $self->type = $entry['type'] ?? null;
        $self->episodes = $entry['episodes'] ?? $mergeEntry['episodes'] ?? null;
        $self->status = $entry['status'] ?? null;
        $self->season = $entry['season'] ?? null;
        $self->year = $entry['year'] ?? null;
        $self->images = array_merge($entry['images'] ?? [], $mergeEntry['images'] ?? []);

        $references = array_merge($entry['references'] ?? [], $mergeEntry['references'] ?? []);
        $self->references = array_unique($references, SORT_STRING);
        // Union the parsed reference IDs instead of re-parsing URLs: re-parsing the
        // merged URL list picks up OTHER entries' source URLs (e.g. a kitsu entry
        // exposes unrelated anilist URLs), corrupting a document's own source IDs.
        // Merge candidate IDs first so the base entry's own source IDs win on
        // conflicts (e.g. a kitsu candidate claiming a different anilist id).
        $self->referenceIds = array_merge($mergeEntry['reference_ids'] ?? [], $entry['reference_ids'] ?? []);

        $tags = array_merge($entry['tags'] ?? [], $mergeEntry['tags'] ?? []);
        $self->tags = array_unique($tags, SORT_STRING);

        return $self;
    }
}
