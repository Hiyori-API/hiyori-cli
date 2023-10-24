<?php

namespace Hiyori\Models\Common\Identifiers;

class Bangumi extends Identifier
{
    public const SHORTHAND = "b";
    public const SOURCE_NAME = "bangumi";
    public const PATTERN = "bangumi\.tv\/subject\/([\d]+)";
    public const ENTRY_URL = "https://bangumi.tv/subject/%s";
}