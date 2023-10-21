<?php

namespace Hiyori\Service;

use Hiyori\Exception\InvalidArgumentException;
use Hiyori\Models\SourceConfiguration;

final class SourceConfigurationFactory
{
    private array $sources;
    private string $current;
    public static function create(): self
    {
        $self = new self();

        $sourceConfigs = include(__DIR__.'/../Config/Sources.php');

        foreach ($sourceConfigs as $sourceName => $sourceConfig) {
            $self->sources[$sourceName] = new SourceConfiguration($sourceConfig);
        }

        return $self;
    }

    public function get(?string $sourceName = null): SourceConfiguration
    {
        if (!is_null($this->current)) {
            return $this->sources[$this->current];
        }

        if (!$this->exists($sourceName)) {
            throw new InvalidArgumentException("Source configuration does not exist [{$sourceName}]");
        }

        return $this->sources[$sourceName];
    }

    public function exists(string $sourceName): bool
    {
        return isset($this->sources[$sourceName]);
    }

    /**
     * @return array
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function setCurrent(string $sourceName): self
    {
        $this->current = $sourceName;
        return $this;
    }
}
