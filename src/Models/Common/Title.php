<?php

namespace Hiyori\Models\Common;

/**
 *
 */
abstract class Title
{
    /**
     * @var string
     */
    protected string $default;
    /**
     * @var array
     */
    protected array $synonyms = [];

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