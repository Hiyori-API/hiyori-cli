<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Exception\ConfigurationException;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Combiner\Strategy\MetadataMlStrategy;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Service\StrategyConfigKeys;
use Hiyori\Service\StrategyConfigurationFactory;
use PHPUnit\Framework\TestCase;

/**
 * The combiner used to accept any JSON and ignore every key it did not know,
 * so `--config '{"thresold":80}'` was a no-op that looked like a setting.
 *
 * Every strategy now declares the keys it reads, and the factory refuses
 * anything else — with the key, the strategy and the accepted keys in the
 * message.
 */
final class StrategyConfigSchemaTest extends TestCase
{
    /**
     * The keys the combiner command reads itself, not a strategy.
     *
     * @var list<string>
     */
    private const COMMAND_KEYS = ['sweep'];

    /**
     * The config keys every strategy reads, taken from its source.
     *
     * @return list<string>
     */
    private function keysReadBy(string $strategyClass): array
    {
        $short = substr((string) strrchr('\\' . $strategyClass, '\\'), 1);
        $path = dirname(__DIR__, 2) . '/src/Service/Combiner/Strategy/' . $short . '.php';
        $source = (string) file_get_contents($path);
        preg_match_all('/getConfig\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    public function testEveryStrategyDeclaresTheKeysItReads(): void
    {
        foreach (StrategyConfigurationFactory::AVAILABLE_STRATEGIES as $strategyClass) {
            $accepted = StrategyConfigKeys::forStrategy($strategyClass::NAME);
            $read = $this->keysReadBy($strategyClass);

            foreach ($read as $key) {
                $this->assertContains(
                    $key,
                    $accepted,
                    sprintf('%s reads "%s" but does not declare it', $strategyClass, $key),
                );
            }
        }
    }

    public function testNoDeclaredKeyIsDeadWeight(): void
    {
        $readSomewhere = [];

        foreach (StrategyConfigurationFactory::AVAILABLE_STRATEGIES as $strategyClass) {
            $read = $this->keysReadBy($strategyClass);
            $readSomewhere = array_merge($readSomewhere, $read);

            foreach (StrategyConfigKeys::PER_STRATEGY[$strategyClass::NAME] ?? [] as $key) {
                $this->assertContains(
                    $key,
                    $read,
                    sprintf('%s declares "%s" but never reads it', $strategyClass, $key),
                );
            }
        }

        foreach (StrategyConfigKeys::SHARED as $key) {
            $this->assertTrue(
                in_array($key, $readSomewhere, true) || in_array($key, self::COMMAND_KEYS, true),
                sprintf('"%s" is shared but no strategy and no command reads it', $key),
            );
        }
    }

    public function testAnUnknownKeyIsRefusedWithTheKeyTheStrategyAndTheAcceptedKeys(): void
    {
        $factory = StrategyConfigurationFactory::create()->setStrategy(MetadataFuzzyStrategy::NAME);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown config key "thresold" (value: int(80)) for strategy "metadata_fuzzy"');

        $factory->setConfig(['thresold' => 80]);
    }

    public function testTheRefusalListsTheAcceptedKeys(): void
    {
        try {
            StrategyConfigurationFactory::create()
                ->setStrategy(MetadataFuzzyStrategy::NAME)
                ->setConfig(['thresold' => 80]);
            $this->fail('the typo must be refused');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('Accepted keys:', $e->getMessage());
            $this->assertStringContainsString('threshold', $e->getMessage());
            $this->assertStringContainsString('title_floor', $e->getMessage());
        }
    }

    public function testAKeyOfAnotherStrategyIsRefused(): void
    {
        $factory = StrategyConfigurationFactory::create()->setStrategy(MetadataFuzzyStrategy::NAME);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown config key "ml_threshold"');

        $factory->setConfig(['ml_threshold' => 0.6]);
    }

    public function testTheSameKeyIsAcceptedByTheStrategyThatReadsIt(): void
    {
        $factory = StrategyConfigurationFactory::create()
            ->setStrategy(MetadataMlStrategy::NAME)
            ->setConfig(['ml_threshold' => 0.6, 'metadata_floor' => 55]);

        $this->assertSame(0.6, $factory->getConfig('ml_threshold'));
        $this->assertSame(55, $factory->getConfig('metadata_floor'));
    }

    public function testTheSharedKeysAreAcceptedByEveryStrategy(): void
    {
        foreach (StrategyConfigurationFactory::AVAILABLE_STRATEGIES as $strategyClass) {
            $factory = StrategyConfigurationFactory::create()->setStrategy($strategyClass::NAME);

            foreach (StrategyConfigKeys::SHARED as $key) {
                $factory->setConfig([$key => 'x']);

                $this->assertSame('x', $factory->getConfig($key));
            }
        }
    }

    public function testGetConfigStillFallsBackToTheDefault(): void
    {
        $factory = StrategyConfigurationFactory::create()->setStrategy(MetadataFuzzyStrategy::NAME);

        $this->assertNull($factory->getConfig('nothing_here'));
        $this->assertSame('fallback', $factory->getConfig('nothing_here', 'fallback'));
    }

    public function testAConfigWithoutAStrategyIsStillChecked(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown config key "thresold"');

        StrategyConfigurationFactory::create()->setConfig(['thresold' => 80]);
    }

    public function testAConfigSetBeforeTheStrategyIsCheckedAgainstIt(): void
    {
        $factory = StrategyConfigurationFactory::create()->setConfig(['ml_threshold' => 0.6]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown config key "ml_threshold"');

        $factory->setStrategy(MetadataFuzzyStrategy::NAME);
    }

    public function testAConfigSetBeforeTheStrategyIsAcceptedWhenItFits(): void
    {
        $factory = StrategyConfigurationFactory::create()
            ->setConfig(['ml_threshold' => 0.6])
            ->setStrategy(MetadataMlStrategy::NAME);

        $this->assertSame(0.6, $factory->getConfig('ml_threshold'));
    }

    public function testTheRelationalStrategiesAcceptTheirOwnKeys(): void
    {
        $factory = StrategyConfigurationFactory::create()
            ->setStrategy(RelationalMappingStrategy::NAME)
            ->setConfig([
                'collection' => 'organized',
                'same_kind_candidates' => false,
                'resume' => true,
            ]);

        $this->assertSame('organized', $factory->getConfig('collection'));
        $this->assertFalse($factory->getConfig('same_kind_candidates'));
        $this->assertTrue($factory->getConfig('resume'));
    }
}
