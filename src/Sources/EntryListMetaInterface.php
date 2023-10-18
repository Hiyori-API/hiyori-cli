<?php

namespace Hiyori\Sources;

interface EntryListMetaInterface
{
    public function getLastPage(): int;
    public function setLastPage(int $page): self;
    public function getTotalEntries(): int;
    public function setTotalEntries(int $totalEntries): self;
    public function getCurrentPage(): int;
    public function setCurrentPage(int $currentPage): self;

    public static function create() : self;
}