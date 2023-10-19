<?php

namespace Hiyori\Models\Common\Identifiers;

class AnimeNewsNetwork extends Identifier
{
    public const SHORTHAND = "ann";
    public const SOURCE_NAME = "anime_news_network";
    public const PATTERN = "^animenewsnetwork\.com\/encyclopedia\/anime\.php\?id=([\d]+)";
    public const ENTRY_URL = "https://www.animenewsnetwork.com/encyclopedia/anime.php?id=%s";
}