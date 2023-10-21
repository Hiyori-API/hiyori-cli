<?php

namespace Hiyori;

use Hiyori\Service\Ingestion\Ingestion;
use Hiyori\Service\SourceConfigurationFactory;

class Hiyori
{
    public function __construct(
        private SourceConfigurationFactory $sources,
        private Ingestion $ingestion
    )
    {
    }

    public function ingest(string $source, array $config): void
    {
        $this->sources
            ->setCurrent($source)
            ->get()
            ->setDelay((int) $config['delay'])
            ->setUpdate((bool) $config['update']);

        $this->ingestion->start();
    }

    public function combine()
    {
        // @todo
    }

}