<?php

namespace Hiyori;

use Hiyori\Service\Combiner\Combiner;
use Hiyori\Service\Database;
use Hiyori\Service\HealthCheck;
use Hiyori\Service\Ingestion\Ingestion;
use Hiyori\Service\Logging;
use Hiyori\Service\MetricsCollector;
use Hiyori\Service\RateLimiter;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;

class Hiyori
{
    public function __construct(
        private SourceConfigurationFactory $sources,
        private Ingestion $ingestion,
        private Combiner $combiner,
        private StrategyConfigurationFactory $strategy,
        private Logging $logging,
        private Database $database,
        private RateLimiter $rateLimiter,
        private MetricsCollector $metricsCollector,
        private HealthCheck $healthCheck
    ) {
    }

    public function ingest(string $source, array $config): void
    {
        $this->logging->getLogger()->debug('Started ingestion of ' . $source, $config);

        $this->sources
            ->setCurrent($source)
            ->get()
            ->setDelay((int) $config['delay'])
            ->setUpdate((bool) $config['update']);

        if (isset($config['resume_page'])) {
            $this->sources->get()->setResumePage($config['resume_page']);
        }

        $this->ingestion->start();
    }

    public function combine(string $baseSource, string $strategy, array $config = [])
    {
        $this->sources
            ->setCurrent($baseSource);

        $this->strategy
            ->setStrategy($strategy);

        if ($config !== []) {
            $this->strategy->setConfig($config);
        }

        $this->combiner->start();
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function getMetrics(): MetricsCollector
    {
        return $this->metricsCollector;
    }

    public function getHealthCheck(): HealthCheck
    {
        return $this->healthCheck;
    }
}
