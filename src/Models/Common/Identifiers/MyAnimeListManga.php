<?php

namespace Hiyori\Models\Common\Identifiers;

class MyAnimeListManga extends Identifier
{
    public const SHORTHAND = 'mal_manga';

    public const SOURCE_NAME = 'myanimelist_manga';

    public const PATTERN = "myanimelist\.net\/manga\/([\d]+)";

    public const ENTRY_URL = 'https://myanimelist.net/manga/%s';
}
