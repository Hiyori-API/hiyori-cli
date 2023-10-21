<?php

namespace Hiyori\Requests;

interface EntryInterface
{
    public function getData(): array;
    public function setData(array $list): self;
    public static function create(int &$id) : self;
}