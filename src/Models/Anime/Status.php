<?php

namespace Hiyori\Models\Anime;

/**
 *
 */
enum Status: string
{
    case FINISHED = 'finished';
    case AIRING = 'airing';
    case TO_BE_AIRED = 'to_be_aired';

    public static function fromString(string $status): Status
    {
        return match(true) {
            $status === 'finished' => Status::FINISHED,
            $status === 'finished airing' => Status::FINISHED,
            $status === 'completed' => Status::FINISHED,
            $status === 'airing' => Status::AIRING,
            $status === 'currently airing' => Status::AIRING,
            $status === 'ongoing' => Status::AIRING,
            $status === 'not yet aired' => Status::TO_BE_AIRED,
            $status === 'to be aired' => Status::TO_BE_AIRED,
            default => throw new \Exception('STATUS: Unexpected match value '.$status)
        };
    }
}