<?php

namespace Hiyori\Models\Common\Identifiers;

class Twitter extends Identifier
{
    public const SHORTHAND = "tx";
    public const SOURCE_NAME = "twitter";
    public const PATTERN = "twitter\.com\/(?:@|)([A-Za-z0-9_]{1,15})";
    public const ENTRY_URL = "https://twitter.com/%s";
}