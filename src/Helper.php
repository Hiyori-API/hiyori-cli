<?php

namespace Hiyori;

use Hiyori\Models\Anime\Season;

class Helper
{
    public static function cleanse(string $string): string
    {
        return trim($string);
    }

    public static function prepareAsIdentifer(string $string): string
    {
        return strtolower(
            self::cleanse($string)
        );
    }

    public static function toArray(string $json): array
    {
        return json_decode($json, true);
    }
}