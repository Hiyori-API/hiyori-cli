<?php

namespace Hiyori\Models\Common\Identifiers;

class TVTokyo extends Identifier
{
    public const SHORTHAND = "tvt";
    public const SOURCE_NAME = "tv_tokyo";
    public const PATTERN = "tv-tokyo\.co\.jp\/anime\/([\w\d]+)";
    public const ENTRY_URL = "http://www.tv-tokyo.co.jp/anime/%s";
}