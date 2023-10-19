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

    public static function fromMonth(string $month): Season
    {
        return match (true) {
            in_array($month, [1, 2, 3]) => Season::WINTER,
            in_array($month, [4, 5, 6]) => Season::SPRING,
            in_array($month, [7, 8, 9]) => Season::SUMMER,
            in_array($month, [10, 11, 12]) => Season::FALL,
            default => throw new \Exception('Could not generate seasonal string'),
        };
    }
}