<?php

namespace Hiyori\Models\Anime\Base;

use Hiyori\Helper;
use Hiyori\Models\Anime\Season;
use Hiyori\Models\Anime\Status;
use Hiyori\Models\Anime\Type;
use Hiyori\Models\Common\Base;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Common\MyAnimeListTitle;
use Hiyori\Models\Common\Title;

class MyAnimeListBase extends Base
{
    public static function create(array $json): self
    {
        $self = new self;

        $titles = new MyAnimeListTitle($json['title'], $json['titles']);
        $self->title = $titles->getDefault();
        $self->synonyms = $titles->getSynonyms();
        $self->type = $json['type'] === null ? null : Type::fromString(Helper::prepareAsIdentifer($json['type']))->value;
        $self->episodes = $json['episodes'];
        $self->status = Status::fromString(Helper::prepareAsIdentifer($json['status']))->value;
        $self->season = $json['season'] === null ? null : Season::fromString(Helper::prepareAsIdentifer($json['season']))->value;
        $self->year = $json['year'];
        $self->images[] = $json['images']['jpg']['image_url'];

        $self->references[] = $json['url'];
        foreach ($json['external'] as $link) {
            $self->references[] = $link['url'];
        };
        foreach ($json['streaming'] as $link) {
            $self->references[] = $link['url'];
        };

        $self->referenceIds = (new Identifiers($self->references))->getModel();

        foreach ($json['genres'] as $tag) {
            $self->tags[] = Helper::prepareAsIdentifer($tag['name']);
        };
        foreach ($json['explicit_genres'] as $tag) {
            $self->tags[] = Helper::prepareAsIdentifer($tag['name']);
        };
        foreach ($json['themes'] as $tag) {
            $self->tags[] = Helper::prepareAsIdentifer($tag['name']);
        };
        foreach ($json['demographics'] as $tag) {
            $self->tags[] = Helper::prepareAsIdentifer($tag['name']);
        };

        return $self;
    }
}