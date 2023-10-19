<?php

namespace Hiyori\Models\Common\Identifiers;

class WikipediaEN extends Identifier
{
    public const SHORTHAND = "wp_en";
    public const SOURCE_NAME = "WikipediaEN";
    public const PATTERN = "en\.wikipedia\.org\/wiki\/(.*)";
    public const ENTRY_URL = "https://en.wikipedia.org/wiki/%s";
}