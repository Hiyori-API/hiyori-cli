<?php

namespace Hiyori;

use Hiyori\Service\Combiner\Combiner;
use Hiyori\Service\Ingestion\Ingestion;
use Hiyori\Service\Logging;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;

class Hiyori
{
    public function __construct(
        private SourceConfigurationFactory $sources,
        private Ingestion $ingestion,
        private Combiner $combiner,
        private StrategyConfigurationFactory $strategy,
        private Logging $logging
    )
    {
    }

    public function ingest(string $source, array $config): void
    {
        $this->logging->getLogger()->debug("Started ingestion of ".$source, $config);

        $this->sources
            ->setCurrent($source)
            ->get()
            ->setDelay((int) $config['delay'])
            ->setUpdate((bool) $config['update']);

        $this->ingestion->start();
    }

    public function combine(string $baseSource, string $strategy)
    {
        $this->sources
            ->setCurrent($baseSource);

        $this->strategy
            ->setStrategy($strategy);

        $this->combiner->start();
    }

}