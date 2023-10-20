<?php

namespace Hiyori\Models\Anime\Base;

use Hiyori\Helper;
use Hiyori\Models\Anime\Season;
use Hiyori\Models\Anime\Status;
use Hiyori\Models\Anime\Type;
use Hiyori\Models\Common\Base;
use Hiyori\Models\Common\Identifiers\Identifiers;
use Hiyori\Models\Common\KitsuTitle;
use Hiyori\Models\Common\Title;

class KitsuBase extends Base
{
    public static function create(array $json): self
    {
        $self = new self;

        $titles = array_values($json['attributes']['titles']) ?? [];
        $abbreviatedTitles = $json['attributes']['abbreviatedTitles'] ?? [];
        $titles = new KitsuTitle($json['attributes']['canonicalTitle'], array_merge($titles, $abbreviatedTitles));
        $self->title = $titles->getDefault();
        $self->synonyms = $titles->getSynonyms();

        $self->type = Type::fromString(Helper::prepareAsIdentifer($json['attributes']['subtype']))->value;
        $self->episodes = $json['attributes']['episodeCount'];
        $self->status = Status::fromString(Helper::prepareAsIdentifer($json['attributes']['status']))->value;


        try {
            $date = new \DateTime($json['attributes']['startDate'], new \DateTimeZone('Asia/Tokyo'));
            $year = (int) $date->format('Y');
            $month = (int) $date->format('n');
        } catch (\Exception $e) {
            $date = null;
        }

        $self->season = $date === null ? null : Season::fromMonth($month)->value;
        $self->year = $date === null ? null : $year;

        $self->images[] = $json['attributes']['posterImage']['original'];

        $self->references[] = "https://kitsu.io/anime/".$json['id']."/".$json['attributes']['slug'];

        $pairs = [];
        foreach ($json['external'] ?? [] as $link) {
            $pairs[] = [explode("/",$link['attributes']['externalSite'])[0],$link['attributes']['externalId']];
        };


        foreach ($json['streaming'] ?? [] as $link) {
            $self->references[] = $link['attributes']['url'];
        };

        $self->references = array_merge($self->references, Identifiers::pairsToLinks($pairs));
        $self->referenceIds = (new Identifiers($self->references))->getModel();

        return $self;
    }
}