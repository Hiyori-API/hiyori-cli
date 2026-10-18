<?php

namespace Hiyori\Models\Common\Identifiers;

class KitsuManga extends Identifier
{
    public const SHORTHAND = 'k_manga';

    public const SOURCE_NAME = 'kitsu_manga';

    public const PATTERN = "kitsu\.io\/manga\/([\d]+)";

    public const ENTRY_URL = 'https://kitsu.io/manga/%s';
}
