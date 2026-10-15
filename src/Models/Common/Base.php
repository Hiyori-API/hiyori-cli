<?php

namespace Hiyori\Models\Common;

abstract class Base implements BaseInterface
{
    protected string $title;

    protected array $synonyms = [];

    protected ?string $type = null;

    protected ?int $episodes = null;

    protected ?int $chapters = null;

    protected ?int $volumes = null;

    protected ?string $status = null;

    protected ?string $season = null;

    protected ?int $year = null;

    protected array $images = [];

    protected array $referenceIds = [];

    protected array $references = [];

    protected array $tags = [];

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getSynonyms(): array
    {
        return $this->synonyms;
    }

    public function setSynonyms(array $synonyms): self
    {
        $this->synonyms = $synonyms;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getEpisodes(): ?int
    {
        return $this->episodes;
    }

    public function setEpisodes(?int $episodes): self
    {
        $this->episodes = $episodes;

        return $this;
    }

    public function getChapters(): ?int
    {
        return $this->chapters;
    }

    public function setChapters(?int $chapters): self
    {
        $this->chapters = $chapters;

        return $this;
    }

    public function getVolumes(): ?int
    {
        return $this->volumes;
    }

    public function setVolumes(?int $volumes): self
    {
        $this->volumes = $volumes;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSeason(): ?string
    {
        return $this->season;
    }

    public function setSeason(?string $season): self
    {
        $this->season = $season;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): self
    {
        $this->year = $year;

        return $this;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    public function setImages(array $images): self
    {
        $this->images = $images;

        return $this;
    }

    public function getReferenceIds(): array
    {
        return $this->referenceIds;
    }

    public function setReferenceIds(array $referenceIds): self
    {
        $this->referenceIds = $referenceIds;

        return $this;
    }

    public function getReferences(): array
    {
        return $this->references;
    }

    public function setReferences(array $references): self
    {
        $this->references = $references;

        return $this;
    }

    /**
     * Field map compatible with {@see AnimeBase::merge()} / MangaBase::merge()
     * and with the Mongo document shape (plural `images`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title ?? '',
            'synonyms' => $this->synonyms,
            'type' => $this->type,
            'episodes' => $this->episodes,
            'chapters' => $this->chapters,
            'volumes' => $this->volumes,
            'status' => $this->status,
            'season' => $this->season,
            'year' => $this->year,
            'images' => $this->images,
            'reference_ids' => $this->referenceIds,
            'references' => $this->references,
            'tags' => $this->tags,
        ];
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }
}
