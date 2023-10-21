<?php

namespace Hiyori\Requests;

/**
 *
 */
abstract class Entry implements EntryInterface
{

    /**
     * @var array
     */
    private array $data;

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }



}