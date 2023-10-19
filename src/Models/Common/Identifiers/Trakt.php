<?php

namespace Hiyori\Models\Common\Identifiers;

class Trakt extends Identifier
{
    public const SHORTHAND = "t";
    public const SOURCE_NAME = "trakt";
    public const PATTERN = "trakt\.tv\/shows\/(.*)";
    public const ENTRY_URL = "https://trakt.tv/shows/%s";
}