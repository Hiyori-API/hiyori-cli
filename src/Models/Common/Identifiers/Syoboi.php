<?php

namespace Hiyori\Models\Common\Identifiers;

class Syoboi extends Identifier
{
    public const SHORTHAND = "sb";
    public const SOURCE_NAME = "syoboi";
    public const PATTERN = "cal\.syoboi\.jp\/tid\/([\d]+)";
    public const ENTRY_URL = "https://cal.syoboi.jp/tid/%s";
}