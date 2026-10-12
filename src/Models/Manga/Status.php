<?php

namespace Hiyori\Models\Manga;

/**
 * Manga publication status
 */
enum Status: string
{
    case FINISHED = 'finished';
    case PUBLISHING = 'publishing';
    case NOT_YET_PUBLISHED = 'not_yet_published';
    case ON_HIATUS = 'on_hiatus';
    case DISCONTINUED = 'discontinued';

    public static function fromString(string $status): Status
    {
        return match(true) {
            in_array($status, ['finished', 'completed', 'ended', 'complete']) => Status::FINISHED,
            in_array($status, ['publishing', 'ongoing', 'current', 'releasing']) => Status::PUBLISHING,
            in_array($status, ['not yet published', 'not_yet_published', 'upcoming', 'not yet released', 'tba', 'unreleased']) => Status::NOT_YET_PUBLISHED,
            in_array($status, ['on hiatus', 'on_hiatus', 'hiatus', 'paused']) => Status::ON_HIATUS,
            in_array($status, ['discontinued', 'cancelled', 'dropped', 'hiatus']) => Status::DISCONTINUED,
            default => throw new \Exception('MANGA STATUS: Unexpected match value ' . $status)
        };
    }
}
