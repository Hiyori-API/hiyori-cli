<?php

namespace Hiyori\Models\Common\Identifiers;

class AniDB extends Identifier
{
    public const SHORTHAND = "anidb";
    public const SOURCE_NAME = "anidb";
    public const PATTERN = "^anidb\.net\/perl-bin\/animedb\.pl\?show=anime&aid=([\d]+)"; // @todo Add support for multi patterns. MAL has a different format compared to anidb paths
    public const ENTRY_URL = "https://anidb.net/anime/%s";
}