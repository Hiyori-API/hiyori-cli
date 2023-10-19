<?php

namespace Hiyori\Models\Common;

class KitsuTitle extends Title
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