<?php

namespace Hiyori\Models\Common\Identifiers;

class Netflix extends Identifier
{
    public const SHORTHAND = "nf";
    public const SOURCE_NAME = "netflix";
    public const PATTERN = "netflix\.com\/title\/([\d]+)";
    public const ENTRY_URL = "https://www.netflix.com/title/%s";
}