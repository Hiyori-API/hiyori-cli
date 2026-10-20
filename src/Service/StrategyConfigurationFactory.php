<?php

namespace Hiyori\Service;

use Hiyori\Exception\ConfigurationException;
use Hiyori\Exception\InvalidArgumentException;
use Hiyori\Service\Combiner\Strategy\CombinerStrategy;
use Hiyori\Service\Combiner\Strategy\FellegiSunterStrategy;
use Hiyori\Service\Combiner\Strategy\GraphMergeStrategy;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Combiner\Strategy\MetadataMlStrategy;
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
        MetadataMlStrategy::class,
    ];

    private ?CombinerStrategy $strategy = null;

    private array $config = [];

    /** True while a config waits for the strategy that will read it. */
    private bool $configWaitsForStrategy = false;

    public static function create(): self
    {
        return new self();
    }

    public function get(): CombinerStrategy
    {
        if ($this->strategy === null) {
            throw new InvalidArgumentException('No combiner strategy selected');
        }

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
        // Reset first: otherwise a bogus name leaves the previous strategy in
        // place and the null check below never fires.
        $this->strategy = null;
        $selected = null;
        foreach (self::AVAILABLE_STRATEGIES as $availableStrategy) {
            if ($availableStrategy::NAME === $strategy) {
                $selected = new $availableStrategy();
                break;
            }
        }

        if ($selected === null) {
            throw new InvalidArgumentException("Unknown strategy called [{$strategy}]");
        }

        // A config that was handed over before any strategy was chosen is
        // checked against the strategy that is chosen now: the keys it
        // accepts are the strategy's own.
        if ($this->configWaitsForStrategy) {
            self::assertAcceptedConfig($selected::NAME, $this->config);
            $this->configWaitsForStrategy = false;
        }

        $this->strategy = $selected;

        return $this;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): self
    {
        if ($this->strategy === null) {
            // No strategy yet: the keys are checked against every strategy, and
            // again against the one that is selected later.
            self::assertAcceptedConfig(null, $config);
            $this->configWaitsForStrategy = true;
        } else {
            self::assertAcceptedConfig($this->strategy::NAME, $config);
        }

        $this->config = $config;

        return $this;
    }

    /**
     * The config keys a strategy accepts.
     *
     * @return list<string>
     */
    public static function acceptedKeys(?string $strategy): array
    {
        if ($strategy === null) {
            return StrategyConfigKeys::all();
        }

        return StrategyConfigKeys::forStrategy($strategy);
    }

    /**
     * Refuse a config that carries a key the strategy does not read.
     *
     * A key nobody reads is a typo (`{"thresold":80}`) or a key that belongs
     * to another strategy (`{"ml_threshold":0.6}` on `metadata_fuzzy`). Both
     * used to be accepted and silently did nothing, which is exactly how a
     * run ends up scoring on something the operator did not ask for.
     *
     * @param array<string, mixed> $config
     */
    public static function assertAcceptedConfig(?string $strategy, array $config): void
    {
        $accepted = self::acceptedKeys($strategy);

        $unknown = [];
        foreach ($config as $key => $value) {
            if (!in_array((string) $key, $accepted, true)) {
                $unknown[(string) $key] = $value;
            }
        }

        if ($unknown === []) {
            return;
        }

        $described = [];
        foreach ($unknown as $key => $value) {
            $described[] = sprintf('"%s" (value: %s)', $key, ConfigurationException::describe($value));
        }
        sort($described);

        throw new ConfigurationException(sprintf(
            'Unknown config %s %s for %s. Accepted keys: %s',
            count($described) === 1 ? 'key' : 'keys',
            implode(', ', $described),
            $strategy === null
                ? 'the combiner (no strategy selected)'
                : sprintf('strategy "%s"', $strategy),
            implode(', ', $accepted),
        ));
    }
}
