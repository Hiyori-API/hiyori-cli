<?php

namespace Hiyori\Models\Manga;

/**
 * Manga type/format
 */
enum Type: string
{
    case MANGA = 'manga';
    case NOVEL = 'novel';
    case LIGHT_NOVEL = 'light_novel';
    case ONE_SHOT = 'one_shot';
    case DOUJINSHI = 'doujinshi';
    case MANHWA = 'manhwa';
    case MANHUA = 'manhua';
    case OEL = 'oel';

    public static function fromString(string $type): Type
    {
        return match(true) {
            in_array($type, ['manga']) => Type::MANGA,
            in_array($type, ['novel']) => Type::NOVEL,
            in_array($type, ['light_novel', 'light novel', 'ln']) => Type::LIGHT_NOVEL,
            in_array($type, ['one_shot', 'one-shot', 'oneshot']) => Type::ONE_SHOT,
            in_array($type, ['doujinshi', 'doujin']) => Type::DOUJINSHI,
            in_array($type, ['manhwa']) => Type::MANHWA,
            in_array($type, ['manhua']) => Type::MANHUA,
            in_array($type, ['oel', 'original english']) => Type::OEL,
            default => throw new \Exception('MANGA TYPE: Unexpected match value ' . $type)
        };
    }
}
