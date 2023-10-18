<?php

namespace Hiyori\Sources;

interface EntryInterface
{
    public function getData(): array;
    public function setData(array $list): self;
    public static function create(int &$id) : self;
}