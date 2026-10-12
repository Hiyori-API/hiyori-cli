<?php

namespace Hiyori\Models\Manga\Base;

use Hiyori\Helper;
use Hiyori\Models\Manga\Status;
use Hiyori\Models\Manga\Type;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Manga\Base\MangaBase;

class AniListBase extends MangaBase
{
    public static function create(array $json): self
    {
        $self = new self();

        $node = $json['node'] ?? $json;
        
        $self->title = $node['title']['romaji'] ?? $node['title']['english'] ?? $node['title']['native'] ?? '';
        $self->synonyms = $node['synonyms'] ?? [];
        if (!empty($node['title']['english'])) $self->synonyms[] = $node['title']['english'];
        if (!empty($node['title']['native'])) $self->synonyms[] = $node['title']['native'];
        $self->synonyms = array_unique($self->synonyms, SORT_STRING);

        $self->type = Type::fromString(Helper::prepareAsIdentifer($node['format'] ?? ''))->value;
        $self->chapters = $node['chapters'] ?? null;
        $self->volumes = $node['volumes'] ?? null;
        $self->status = Status::fromString(Helper::prepareAsIdentifer($node['status'] ?? ''))->value;
        $self->year = $node['startDate']['year'] ?? null;

        $self->images[] = $node['coverImage']['extraLarge'] ?? $node['coverImage']['large'] ?? $node['coverImage']['medium'] ?? '';

        $self->references[] = "https://anilist.co/manga/{$node['id']}";

        $pairs = [];
        foreach ($node['externalLinks'] ?? [] as $link) {
            $site = parse_url($link['url'], PHP_URL_HOST) ?? '';
            $id = basename($link['url']);
            if ($site && $id) {
                $pairs[] = [$site, $id];
            }
        }

        foreach ($node['relations']['edges'] ?? [] as $rel) {
            if (!empty($rel['node']['siteUrl'])) {
                $self->references[] = $rel['node']['siteUrl'];
            }
        }

        $self->references = array_merge($self->references, \Hiyori\Models\Common\Identifiers\Identifiers::pairsToLinks($pairs));
        $self->referenceIds = (new Identifiers($self->references))->getModel();

        foreach ($node['genres'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre);
        }
        foreach ($node['tags'] ?? [] as $tag) {
            if (!empty($tag['name'])) {
                $self->tags[] = Helper::prepareAsIdentifer($tag['name']);
            }
        }

        return $self;
    }
}
