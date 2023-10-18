<?php

namespace Hiyori\Models\Common\Identifiers;

class WikipediaJA extends Identifier
{
    public const SHORTHAND = "wp_ja";
    public const SOURCE_NAME = "WikipediaJA";
    public const PATTERN = "^http:\/\/ja\.wikipedia\.org\/wiki\/(.*)";
}