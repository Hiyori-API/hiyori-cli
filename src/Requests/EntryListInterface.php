<?php

namespace Hiyori\Requests;

interface EntryListInterface
{
    public function getList(): array;
    public function setList(array $list): self;
    public static function create(&$currentPage) : self;
}