<?php

namespace Hiyori\Sources;

/**
 *
 */
abstract class EntryListMeta implements EntryListMetaInterface
{
    /**
     * @var int
     */
    private int $lastPage;

    /**
     * @var int
     */
    private int $currentPage = 1;

    /**
     * @var int
     */
    private int $totalEntries;

    /**
     * @return int
     */
    public function getTotalEntries(): int
    {
        return $this->totalEntries;
    }

    /**
     * @param int $totalEntries
     */
    public function setTotalEntries(int $totalEntries): self
    {
        $this->totalEntries = $totalEntries;
        return $this;
    }

    /**
     * @return int
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * @param int $currentPage
     */
    public function setCurrentPage(int $currentPage): self
    {
        $this->currentPage = $currentPage;
        return $this;
    }

    /**
     * @return $this
     */
    public function incrementCurrentPage(): self
    {
        $this->currentPage++;
        return $this;
    }

    /**
     * @return int
     */
    public function getLastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * @param int $page
     */
    public function setLastPage(int $page): self
    {
        $this->lastPage = $page;
        return $this;
    }
}