<?php

namespace Hiyori\Models\Common;

abstract class Base implements BaseInterface
{
    protected string $title;
    protected array $synonyms = [];
    protected ?string $type = null;
    protected ?int $episodes = null;
    protected ?string $status = null;
    protected ?string $season = null;
    protected ?int $year = null;
    protected array $images = [];
    protected array $referenceIds = [];

    protected array $references = [];
    protected array $tags = [];

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     * @return Base
     */
    public function setTitle(string $title): Base
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @return array
     */
    public function getSynonyms(): array
    {
        return $this->synonyms;
    }

    /**
     * @param array $synonyms
     * @return Base
     */
    public function setSynonyms(array $synonyms): Base
    {
        $this->synonyms = $synonyms;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string|null $type
     * @return Base
     */
    public function setType(?string $type): Base
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getEpisodes(): ?int
    {
        return $this->episodes;
    }

    /**
     * @param int|null $episodes
     * @return Base
     */
    public function setEpisodes(?int $episodes): Base
    {
        $this->episodes = $episodes;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * @param string|null $status
     * @return Base
     */
    public function setStatus(?string $status): Base
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSeason(): ?string
    {
        return $this->season;
    }

    /**
     * @param string|null $season
     * @return Base
     */
    public function setSeason(?string $season): Base
    {
        $this->season = $season;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getYear(): ?int
    {
        return $this->year;
    }

    /**
     * @param int|null $year
     * @return Base
     */
    public function setYear(?int $year): Base
    {
        $this->year = $year;
        return $this;
    }

    /**
     * @return array
     */
    public function getImages(): array
    {
        return $this->images;
    }

    /**
     * @param array $images
     * @return Base
     */
    public function setImages(array $images): Base
    {
        $this->images = $images;
        return $this;
    }

    /**
     * @return array
     */
    public function getReferenceIds(): array
    {
        return $this->referenceIds;
    }

    /**
     * @param array $referenceIds
     * @return Base
     */
    public function setReferenceIds(array $referenceIds): Base
    {
        $this->referenceIds = $referenceIds;
        return $this;
    }

    /**
     * @return array
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * @param array $references
     * @return Base
     */
    public function setReferences(array $references): Base
    {
        $this->references = $references;
        return $this;
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array $tags
     * @return Base
     */
    public function setTags(array $tags): Base
    {
        $this->tags = $tags;
        return $this;
    }
}