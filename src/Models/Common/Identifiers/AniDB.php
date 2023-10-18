<?php

namespace Hiyori\Models\Common\Identifiers;

class AniDB extends Identifier
{
    public const SHORTHAND = "anidb";
    public const SOURCE_NAME = "anidb";
    public const PATTERN = "^https:\/\/anidb\.net\/perl-bin\/animedb\.pl\?show=anime&aid=([\d]+)";
}