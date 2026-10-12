<?php

namespace Hiyori\Models\Manga\Base;

use Hiyori\Helper;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Manga\Status;
use Hiyori\Models\Manga\Type;

class KitsuBase extends MangaBase
{
    public static function create(array $json): self
    {
        $self = new self();

        $attrs = $json['attributes'] ?? $json;

        $self->title = $attrs['canonicalTitle'] ?? '';
        $self->synonyms = array_values(array_filter(
            array_merge(
                array_values($attrs['titles'] ?? []),
                $attrs['abbreviatedTitles'] ?? [],
            ),
            static fn ($v) => is_string($v) && $v !== '',
        ));
        $self->synonyms = array_unique($self->synonyms, SORT_STRING);

        $self->type = Type::fromString(Helper::prepareAsIdentifer($attrs['subtype'] ?? ''))->value;
        $self->chapters = $attrs['chapterCount'] ?? null;
        $self->volumes = $attrs['volumeCount'] ?? null;
        $self->status = Status::fromString(Helper::prepareAsIdentifer($attrs['status'] ?? ''))->value;

        $year = null;
        $month = null;
        try {
            $date = new \DateTime($attrs['startDate'] ?? '', new \DateTimeZone('Asia/Tokyo'));
            $year = (int)$date->format('Y');
            $month = (int)$date->format('n');
        } catch (\Exception) {
            $date = null;
        }
        $self->year = $year;

        $self->images[] = $attrs['posterImage']['original'] ?? $attrs['posterImage']['large'] ?? '';

        $self->references[] = "https://kitsu.io/manga/{$json['id']}/{$attrs['slug']}";

        $pairs = [];
        foreach ($attrs['external'] ?? [] as $link) {
            $site = explode('/', $link['attributes']['externalSite'])[0] ?? '';
            $id = $link['attributes']['externalId'] ?? '';
            if ($site && $id) {
                $pairs[] = [$site, $id];
            }
        }

        foreach ($attrs['streaming'] ?? [] as $link) {
            $self->references[] = $link['attributes']['url'] ?? '';
        }

        $self->references = array_merge($self->references, \Hiyori\Models\Common\Identifiers\Identifiers::pairsToLinks($pairs));
        $self->referenceIds = (new Identifiers($self->references))->getModel();

        foreach ($attrs['genres'] ?? [] as $genre) {
            $self->tags[] = Helper::prepareAsIdentifer($genre['attributes']['name'] ?? '');
        }
        foreach ($attrs['categories'] ?? [] as $category) {
            $self->tags[] = Helper::prepareAsIdentifer($category['attributes']['title'] ?? '');
        }

        return $self;
    }
}
