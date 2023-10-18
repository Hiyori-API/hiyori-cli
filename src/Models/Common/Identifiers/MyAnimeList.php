<?php

namespace Hiyori\Models\Common\Identifiers;

class MyAnimeList extends Identifier
{
    public const SHORTHAND = "mal";
    public const SOURCE_NAME = "myanimelist";
    public const PATTERN = "^https:\/\/myanimelist\.net\/anime\/([\d]+)";
}