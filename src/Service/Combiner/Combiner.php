<?php

namespace Hiyori\Service\Combiner;

use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;

class Combiner
{
    public function __construct(
        private Database                     $db,
        private ConsoleFactory               $console,
        private StrategyConfigurationFactory $strategy,
        private SourceConfigurationFactory $source
    )
    {
    }

    public function start()
    {
        $this->console->io()
            ->section("Combiner: ".$this->strategy->get()::NAME." | Base: ".$this->source->get()->getName());

        call_user_func($this->strategy->get()::class."::process", $this->db, $this->console, $this->strategy, $this->source);
    }
}