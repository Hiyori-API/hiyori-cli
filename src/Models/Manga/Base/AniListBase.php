<?php

namespace Hiyori\Models\Manga\Base;

use Hiyori\Helper;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Manga\Status;
use Hiyori\Models\Manga\Type;

class AniListBase extends MangaBase
{
    public static function create(array $json): self
    {
        $self = new self();

        $node = $json['node'] ?? $json;

        $self->title = $node['title']['romaji'] ?? $node['title']['english'] ?? $node['title']['native'] ?? '';
        $self->synonyms = $node['synonyms'] ?? [];
        if (!empty($node['title']['english'])) {
            $self->synonyms[] = $node['title']['english'];
        }
        if (!empty($node['title']['native'])) {
            $self->synonyms[] = $node['title']['native'];
        }
        $self->synonyms = array_unique($self->synonyms, SORT_STRING);

        $self->type = Type::fromString(Helper::prepareAsIdentifer($node['format'] ?? ''))->value;
        $self->chapters = $node['chapters'] ?? null;
        $self->volumes = $node['volumes'] ?? null;
        $self->status = Status::fromString(Helper::prepareAsIdentifer($node['status'] ?? ''))->value;
        $self->year = $node['startDate']['year'] ?? null;

        $self->images[] = $node['coverImage']['extraLarge'] ?? $node['coverImage']['large'] ?? $node['coverImage']['medium'] ?? '';

        $ownUrl = "https://anilist.co/manga/{$node['id']}";
        $self->references[] = $ownUrl;

        $pairs = [];
        foreach ($node['externalLinks'] ?? [] as $link) {
            $site = parse_url($link['url'], PHP_URL_HOST) ?? '';
            $id = basename($link['url']);
            if ($site && $id) {
                $pairs[] = [$site, $id];
            }
        }

        // Related titles are links, not aliases: a sequel or an anime
        // adaptation has a different id, and feeding it to getModel() either
        // overwrote this entry's own id or claimed another work's.
        foreach ($node['relations']['edges'] ?? [] as $rel) {
            if (!empty($rel['node']['siteUrl'])) {
                $self->references[] = $rel['node']['siteUrl'];
            }
        }

        $crossLinks = Identifiers::pairsToLinks($pairs, 'manga');
        $self->references = array_merge($self->references, $crossLinks);
        $self->referenceIds = (new Identifiers(array_merge([$ownUrl], $crossLinks)))->getModel();

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
