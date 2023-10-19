<?php

namespace Hiyori\Models\Common\Identifiers;

class Crunchyroll extends Identifier
{
    public const SHORTHAND = "cr";
    public const SOURCE_NAME = "crunchyroll";
    public const PATTERN = "crunchyroll\.com\/(.*)";
    public const ENTRY_URL = "https://www.crunchyroll.com/series/%s";
}