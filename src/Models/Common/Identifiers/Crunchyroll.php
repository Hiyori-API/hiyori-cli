<?php

namespace Hiyori\Models\Common\Identifiers;

class Crunchyroll extends Identifier
{
    public const SHORTHAND = "cr";
    public const SOURCE_NAME = "crunchyroll";
    public const PATTERN = "^http:\/\/www\.crunchyroll\.com\/series-([\d]+)";
}