<?php

namespace Hiyori\Models\Anime;

/**
 *
 */
enum Season: string
{
    case WINTER = 'winter';
    case SUMMER = 'summer';
    case SPRING = 'spring';
    case FALL = 'fall';

    public static function fromString(string $status): Season
    {
        return match(true) {
            $status === 'winter' => Season::WINTER,
            $status === 'summer' => Season::SUMMER,
            $status === 'spring' => Season::SPRING,
            $status === 'fall' => Season::FALL,
            $status === 'autumn' => Season::FALL,
            default => throw new \Exception('SEASON: Unexpected match value')
        };
    }
}