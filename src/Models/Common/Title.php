<?php

namespace Hiyori\Models\Common;

/**
 *
 */
class Title
{
    /**
     * @var string
     */
    private string $default;
    /**
     * @var array
     */
    private array $synonyms = [];

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


    /**
     * @return string
     */
    public function getDefault(): string
    {
        return $this->default;
    }

    /**
     * @return array
     */
    public function getSynonyms(): array
    {
        return $this->synonyms;
    }

}