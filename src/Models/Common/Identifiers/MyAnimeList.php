<?php

namespace Hiyori\Models\Common\Identifiers;

class MyAnimeList extends Identifier
{
    public const SHORTHAND = "mal";
    public const SOURCE_NAME = "myanimelist";
    public const PATTERN = "myanimelist\.net\/anime\/([\d]+)";
    public const ENTRY_URL = "https://myanimelist.net/anime/%s";
}