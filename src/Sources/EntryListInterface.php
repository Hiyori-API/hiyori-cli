<?php

namespace Hiyori\Sources;

interface EntryListInterface
{
    public function getList(): array;
    public function setList(array $list): self;
    public static function create(&$currentPage) : self;
}