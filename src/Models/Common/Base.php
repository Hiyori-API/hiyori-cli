<?php

namespace Hiyori\Models\Common;

abstract class Base implements BaseInterface
{
    protected string $title;
    protected array $synonyms;
    protected ?string $type;
    protected ?int $episodes;
    protected string $status;
    protected ?string $season;
    protected ?int $year;
    protected array $images;
    protected array $referenceIds;

    protected array $references;
    protected array $tags;
}