<?php

namespace Hiyori\Models\Manga\Base;

class MangaBase extends \Hiyori\Models\Common\Base
{
    public static function create(array $json): self
    {
        $self = new self();

        $self->title = $json['title'];
        $self->synonyms = $json['synonyms'];
        $self->type = $json['type'];
        $self->chapters = $json['chapters'] ?? null;
        $self->volumes = $json['volumes'] ?? null;
        $self->status = $json['status'];
        $self->year = $json['year'] ?? null;
        $self->images = $json['images'] ?? [];
        $self->referenceIds = $json['reference_ids'] ?? [];
        $self->references = $json['references'] ?? [];
        $self->tags = $json['tags'] ?? [];

        return $self;
    }

    public static function merge(array|object $entry, array|object $mergeEntry): self
    {
        $self = new self();

        $self->title = $entry['title'] ?? $mergeEntry['title'];

        $synonyms = array_merge($entry['synonyms'] ?? [], $mergeEntry['synonyms'] ?? [], [$mergeEntry['title']]);
        $self->synonyms = array_unique($synonyms, SORT_STRING);

        $self->type = $entry['type'] ?? $mergeEntry['type'] ?? null;
        $self->chapters = $entry['chapters'] ?? $mergeEntry['chapters'] ?? null;
        $self->volumes = $entry['volumes'] ?? $mergeEntry['volumes'] ?? null;
        $self->status = $entry['status'] ?? $mergeEntry['status'] ?? null;
        $self->year = $entry['year'] ?? $mergeEntry['year'] ?? null;
        $self->images = array_merge($entry['images'] ?? [], $mergeEntry['images'] ?? []);

        $references = array_merge($entry['references'] ?? [], $mergeEntry['references'] ?? []);
        $self->references = array_unique($references, SORT_STRING);
        // Merge candidate IDs first so the base entry's own source IDs win on
        // conflicts (e.g. a kitsu candidate claiming a different anilist id).
        $self->referenceIds = array_merge($mergeEntry['reference_ids'] ?? [], $entry['reference_ids'] ?? []);

        $tags = array_merge($entry['tags'] ?? [], $mergeEntry['tags'] ?? []);
        $self->tags = array_unique($tags, SORT_STRING);

        return $self;
    }
}
