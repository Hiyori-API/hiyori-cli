<?php

namespace Hiyori\Service;

use Hiyori\Exception\InvalidArgumentException;
use Hiyori\Service\Combiner\Strategy\CombinerStrategy;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;

final class StrategyConfigurationFactory
{
    public const AVAILABLE_STRATEGIES = [
        RelationalMappingStrategy::class
    ];

    private ?CombinerStrategy $strategy = null;
    private ?string $base = null;
    public static function create(): self
    {
        $self = new self();

        return $self;
    }

    public function get(): CombinerStrategy
    {
        return $this->strategy;
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
}
