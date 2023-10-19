<?php

namespace Hiyori\Models\Common\Identifiers;

class AniList extends Identifier
{
    public const SHORTHAND = "al";
    public const SOURCE_NAME = "anilist";
    public const PATTERN = "anilist\.co\/anime\/([\d]+)";
    public const ENTRY_URL = "https://anilist.co/anime/%s";
}