<?php

namespace Hiyori\Models\Manga\Base;

use Hiyori\Helper;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Manga\Status;
use Hiyori\Models\Manga\Type;

class MyAnimeListBase extends MangaBase
{
    public static function create(array $json): self
    {
        $self = new self();

        $self->title = $json['title'];
        $self->synonyms = $json['alternative_titles']['synonyms'] ?? [];
        if (!empty($json['alternative_titles']['en'])) {
            $self->synonyms[] = $json['alternative_titles']['en'];
        }
        if (!empty($json['alternative_titles']['ja'])) {
            $self->synonyms[] = $json['alternative_titles']['ja'];
        }
        $self->synonyms = array_unique($self->synonyms, SORT_STRING);

        $self->type = Type::fromString(Helper::prepareAsIdentifer($json['media_type'] ?? ''))->value;
        $self->chapters = $json['num_chapters'] ?? null;
        $self->volumes = $json['num_volumes'] ?? null;
        $self->status = Status::fromString(Helper::prepareAsIdentifer($json['status'] ?? ''))->value;
        $self->year = $json['start_date'] ? (int)date('Y', strtotime($json['start_date'])) : null;

        $self->images[] = $json['main_picture']['large'] ?? $json['main_picture']['medium'] ?? '';

        $self->references[] = "https://myanimelist.net/manga/{$json['id']}";

        $pairs = [];
        foreach ($json['external'] ?? [] as $link) {
            $site = explode('/', $link['url'])[0] ?? '';
            $id = basename($link['url']);
            if ($site && $id) {
                $pairs[] = [$site, $id];
            }
        }

        foreach ($json['related_manga'] ?? [] as $rel) {
            if (!empty($rel['url'])) {
                $self->references[] = $rel['url'];
            }
        }

        $self->references = array_merge($self->references, \Hiyori\Models\Common\Identifiers\Identifiers::pairsToLinks($pairs));
        $self->referenceIds = (new Identifiers($self->references))->getModel();

        foreach ($json['genres'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre['name']);
        }

        return $self;
    }
}
