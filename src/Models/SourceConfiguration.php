<?php

namespace Hiyori\Models;

use Hiyori\Exception\ConfigurationException;

/**
 * One source's configuration.
 *
 * The values come from `src/Config/Sources.php`, a PHP file that is read with
 * `include`. A typo in it used to surface as a PHP warning about an undefined
 * array key and then a `TypeError` about a property — neither of which names
 * the file, the source or the key. Every key is therefore checked here, where
 * the source is read, and a bad one is reported with the file, the source, the
 * key and the offending value.
 *
 * The optional keys (`delay`, `update`, `resume_page`) are applied when they
 * are present, so a value written in the config file is not silently dropped.
 */
class SourceConfiguration
{
    /**
     * Keys every source must define. Each one is a non-empty string.
     */
    public const REQUIRED_KEYS = ['name', 'table', 'shorthand', 'json_id', 'ingestion_service'];

    /** The source key in the config file, e.g. `anilist`. */
    private string $source = '';

    /** The config file the source came from, e.g. `sources.php`. */
    private string $file = 'sources.php';

    private string $name;

    private string $table;

    private string $shorthand;

    private string $jsonId;

    private string $ingestionService;

    private int $delay = 0;

    private bool $update = false;

    private ?int $resumePage = null;

    /**
     * @param array<string, mixed> $config
     * @param string                $source the source key in the config file
     * @param string                $file   the config file it was read from
     */
    public function __construct(array $config, string $source = '', string $file = 'sources.php')
    {
        $this->source = $source;
        $this->file = $file;

        $this->name = $this->requiredString($config, 'name');
        $this->table = $this->requiredString($config, 'table');
        $this->shorthand = $this->requiredString($config, 'shorthand');
        $this->jsonId = $this->requiredString($config, 'json_id');
        $this->ingestionService = $this->requiredString($config, 'ingestion_service');

        if (!class_exists($this->ingestionService)) {
            throw new ConfigurationException(sprintf(
                '%s: %s points at an ingestion service that does not exist: "%s"',
                $this->file,
                $this->label(),
                $this->ingestionService,
            ));
        }

        if (array_key_exists('delay', $config)) {
            $delay = $config['delay'];
            if (!is_int($delay)) {
                throw new ConfigurationException(sprintf(
                    '%s: %s has an invalid value for "delay": expected an int, got %s',
                    $this->file,
                    $this->label(),
                    ConfigurationException::describe($delay),
                ));
            }
            $this->delay = $delay;
        }

        if (array_key_exists('update', $config)) {
            $update = $config['update'];
            if (!is_bool($update)) {
                throw new ConfigurationException(sprintf(
                    '%s: %s has an invalid value for "update": expected a bool, got %s',
                    $this->file,
                    $this->label(),
                    ConfigurationException::describe($update),
                ));
            }
            $this->update = $update;
        }

        // `resume_page` is the config-file spelling; `resumePage` is accepted
        // as well so the property name cannot be used by mistake.
        foreach (['resume_page', 'resumePage'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $page = $config[$key];
            if ($page !== null && !is_int($page)) {
                throw new ConfigurationException(sprintf(
                    '%s: %s has an invalid value for "%s": expected an int or null, got %s',
                    $this->file,
                    $this->label(),
                    $key,
                    ConfigurationException::describe($page),
                ));
            }
            $this->resumePage = $page;
        }
    }

    /**
     * A required key, read as a non-empty string.
     *
     * @param array<string, mixed> $config
     */
    private function requiredString(array $config, string $key): string
    {
        if (!array_key_exists($key, $config)) {
            throw new ConfigurationException(sprintf(
                '%s: %s is missing the required key "%s"',
                $this->file,
                $this->label(),
                $key,
            ));
        }

        $value = $config[$key];
        if (!is_string($value)) {
            throw new ConfigurationException(sprintf(
                '%s: %s has an invalid value for "%s": expected a non-empty string, got %s',
                $this->file,
                $this->label(),
                $key,
                ConfigurationException::describe($value),
            ));
        }

        if (trim($value) === '') {
            throw new ConfigurationException(sprintf(
                '%s: %s has an empty value for "%s": expected a non-empty string',
                $this->file,
                $this->label(),
                $key,
            ));
        }

        return $value;
    }

    /** The source as it appears in an error message, e.g. `'anilist'`. */
    private function label(): string
    {
        return $this->source !== '' ? sprintf("'%s'", $this->source) : 'the source';
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
