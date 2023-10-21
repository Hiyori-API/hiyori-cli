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

    /**
     * @param string $name
     * @param string $table
     * @param string $shorthand
     * @param string $jsonId
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->table = $config['table'];
        $this->shorthand = $config['shorthand'];
        $this->jsonId = $config['json_id'];
        $this->ingestionService = $config['ingestion_service'];
    }

    /**
     * @return string
     */
    public function getIngestionService(): string
    {
        return $this->ingestionService;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @param string $table
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * @return string
     */
    public function getShorthand(): string
    {
        return $this->shorthand;
    }

    /**
     * @param string $shorthand
     */
    public function setShorthand(string $shorthand): self
    {
        $this->shorthand = $shorthand;
        return $this;
    }

    /**
     * @return string
     */
    public function getJsonId(): string
    {
        return $this->jsonId;
    }

    /**
     * @param string $jsonId
     */
    public function setJsonId(string $jsonId): self
    {
        $this->jsonId = $jsonId;
        return $this;
    }

    /**
     * @return int
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * @param int $delay
     */
    public function setDelay(int $delay): self
    {
        $this->delay = $delay;
        return $this;
    }

    /**
     * @return bool
     */
    public function isUpdate(): bool
    {
        return $this->update;
    }

    /**
     * @param bool $update
     */
    public function setUpdate(bool $update): self
    {
        $this->update = $update;
        return $this;
    }
}