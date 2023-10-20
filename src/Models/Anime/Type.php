<?php

namespace Hiyori\Models\Anime;

/**
 *
 */
enum Type: string
{
    case TV = 'tv';
    case MOVIE = 'movie';
    case OVA = 'ova';
    case ONA = 'ona';
    case SPECIAL = 'special';
    case MUSIC = 'music;';

    public static function fromString(string $status): Type
    {
        return match(true) {
            $status === 'tv', $status === 'tv_short' => Type::TV,
            $status === 'movie' => Type::MOVIE,
            $status === 'ova' => Type::OVA,
            $status === 'ona' => Type::ONA,
            $status === 'special' => Type::SPECIAL,
            $status === 'music' => Type::MUSIC,
            default => throw new \Exception('TYPE: Unexpected match value')
        };
    }
}