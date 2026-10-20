<?php

namespace Hiyori\Service;

use Hiyori\Exception\ConfigurationException;
use Hiyori\Exception\InvalidArgumentException;
use Hiyori\Models\SourceConfiguration;

final class SourceConfigurationFactory
{
    /** The file every source configuration is read from. */
    private const CONFIG_FILE = __DIR__ . '/../Config/Sources.php';

    private array $sources = [];

    private ?string $current = null;

    public static function create(): self
    {
        $self = new self();

        if (!is_file(self::CONFIG_FILE)) {
            throw new ConfigurationException(sprintf(
                '%s: the source configuration file is missing',
                self::fileName(),
            ));
        }

        $sourceConfigs = include self::CONFIG_FILE;

        if (!is_array($sourceConfigs)) {
            throw new ConfigurationException(sprintf(
                '%s: must return an array of source configurations, got %s',
                self::fileName(),
                get_debug_type($sourceConfigs),
            ));
        }

        foreach ($sourceConfigs as $sourceName => $sourceConfig) {
            if (!is_array($sourceConfig)) {
                throw new ConfigurationException(sprintf(
                    '%s: "%s" must be an array of source keys, got %s',
                    self::fileName(),
                    (string) $sourceName,
                    get_debug_type($sourceConfig),
                ));
            }

            $self->sources[(string) $sourceName] = new SourceConfiguration($sourceConfig, (string) $sourceName, self::fileName());
        }

        return $self;
    }

    /** The config file name as it appears in error messages. */
    private static function fileName(): string
    {
        return basename(self::CONFIG_FILE);
    }

    public function get(?string $sourceName = null): SourceConfiguration
    {
        if ($this->current !== null) {
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
     * @return array<string, SourceConfiguration>
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
