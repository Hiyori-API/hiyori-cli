<?php

namespace Hiyori\Models\Common\Identifiers;

class WikipediaEN extends Identifier
{
    public const SHORTHAND = "wp_en";
    public const SOURCE_NAME = "WikipediaEN";
    public const PATTERN = "^http:\/\/en\.wikipedia\.org\/wiki\/(.*)";
}