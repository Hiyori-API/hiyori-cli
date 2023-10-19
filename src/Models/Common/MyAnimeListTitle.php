<?php

namespace Hiyori\Models\Common;

class MyAnimeListTitle extends Title
{
    /**
     * @param string $default
     * @param array $synonyms
     */
    public function __construct(string $default, array $synonyms)
    {
        $this->default = $default;

        $synonymsTransformed = [];
        foreach ($synonyms as $synonym) {
            $synonymsTransformed[] = $synonym['title'];
        }

        $this->synonyms = array_unique($synonymsTransformed, SORT_STRING);
    }
}