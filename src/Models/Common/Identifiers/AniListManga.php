<?php

namespace Hiyori\Models\Common\Identifiers;

class AniListManga extends Identifier
{
    public const SHORTHAND = 'al_manga';

    public const SOURCE_NAME = 'anilist_manga';

    public const PATTERN = "anilist\.co\/manga\/([\d]+)";

    public const ENTRY_URL = 'https://anilist.co/manga/%s';
}
