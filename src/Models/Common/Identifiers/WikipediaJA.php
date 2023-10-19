<?php

namespace Hiyori\Models\Common\Identifiers;

class WikipediaJA extends Identifier
{
    public const SHORTHAND = "wpja";
    public const SOURCE_NAME = "WikipediaJA";
    public const PATTERN = "ja\.wikipedia\.org\/wiki\/(.*)";
    public const ENTRY_URL = "https://ja.wikipedia.org/wiki/%s";
}