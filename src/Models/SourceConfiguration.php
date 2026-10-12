<?php

namespace Hiyori\Models;

class SourceConfiguration
{
    private string $name;

    private string $table;

    private string $shorthand;

    private string $jsonId;

    private string $ingestionService;

    private int $delay = 0;

    private bool $update = false;

    private ?int $resumePage = null;

    /**
     * @param array{name: string, table: string, shorthand: string, json_id: string, ingestion_service: string} $config
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->table = $config['table'];
        $this->shorthand = $config['shorthand'];
        $this->jsonId = $config['json_id'];
        $this->ingestionService = $config['ingestion_service'];
    }

    public function getIngestionService(): string
    {
        return $this->ingestionService;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function getShorthand(): string
    {
        return $this->shorthand;
    }

    public function setShorthand(string $shorthand): self
    {
        $this->shorthand = $shorthand;

        return $this;
    }

    public function getJsonId(): string
    {
        return $this->jsonId;
    }

    public function setJsonId(string $jsonId): self
    {
        $this->jsonId = $jsonId;

        return $this;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function setDelay(int $delay): self
    {
        $this->delay = $delay;

        return $this;
    }

    public function isUpdate(): bool
    {
        return $this->update;
    }

    public function setUpdate(bool $update): self
    {
        $this->update = $update;

        return $this;
    }

    public function getResumePage(): ?int
    {
        return $this->resumePage;
    }

    public function setResumePage(?int $resumePage): self
    {
        $this->resumePage = $resumePage;

        return $this;
    }
}
