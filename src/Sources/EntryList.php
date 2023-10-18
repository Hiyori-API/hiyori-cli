<?php

namespace Hiyori\Sources;


/**
 *
 */
abstract class EntryList implements EntryListInterface
{

    /**
     * @var array
     */
    private array $list;

    /**
     * @return array
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param array $list
     */
    public function setList(array $list): self
    {
        $this->list = $list;
        return $this;
    }

}