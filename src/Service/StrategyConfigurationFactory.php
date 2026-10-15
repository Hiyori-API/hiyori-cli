<?php

namespace Hiyori\Service;

use Hiyori\Exception\InvalidArgumentException;
use Hiyori\Service\Combiner\Strategy\CombinerStrategy;
use Hiyori\Service\Combiner\Strategy\FellegiSunterStrategy;
use Hiyori\Service\Combiner\Strategy\GraphMergeStrategy;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Combiner\Strategy\RelationalFuzzyStrategy;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Service\Combiner\Strategy\SemanticRerankStrategy;

final class StrategyConfigurationFactory
{
    public const AVAILABLE_STRATEGIES = [
        RelationalMappingStrategy::class,
        RelationalFuzzyStrategy::class,
        MetadataFuzzyStrategy::class,
        GraphMergeStrategy::class,
        FellegiSunterStrategy::class,
        SemanticRerankStrategy::class,
    ];

    private ?CombinerStrategy $strategy = null;

    private array $config = [];

    public static function create(): self
    {
        return new self();
    }

    public function get(): CombinerStrategy
    {
        return $this->strategy;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @param string|null $strategy
     */
    public function setStrategy(?string $strategy): self
    {
        foreach (self::AVAILABLE_STRATEGIES as $availableStrategy) {
            if ($availableStrategy::NAME === $strategy) {
                $this->strategy = new $availableStrategy();
            }
        }

        if ($this->strategy === null) {
            throw new InvalidArgumentException("Unknown strategy called [{$strategy}]");
        }

        return $this;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }
}
