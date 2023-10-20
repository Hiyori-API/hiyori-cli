<?php

namespace Hiyori\Models\Common;

class AniListTitle extends Title
{
    /**
     * @param string $default
     * @param array $synonyms
     */
    public function __construct(string $default, array $synonyms)
    {
        $this->default = $default;
        $this->synonyms = array_unique($synonyms, SORT_STRING);
    }
}