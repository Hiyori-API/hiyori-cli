<?php

namespace Hiyori\Models\Common\Identifiers;

class Kitsu extends Identifier
{
    public const SHORTHAND = "k";
    public const SOURCE_NAME = "kitsu";
    public const PATTERN = "kitsu\.io\/anime\/([\d]+)";
    public const ENTRY_URL = "https://kitsu.io/anime/%s";
}