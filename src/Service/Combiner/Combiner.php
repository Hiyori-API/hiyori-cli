<?php

namespace Hiyori\Service\Combiner;

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
    ) {
    }

    /**
     * Run the configured strategy and return what it did with the base rows.
     *
     * The return value is the coverage contract: every strategy's `process()`
     * must return {@see CombineStats}, so a run that examines nothing or writes
     * almost nothing can be told apart from a healthy one by the caller.
     */
    public function start(): CombineStats
    {
        $this->console->io()
            ->section('Combiner: ' . $this->strategy->get()::NAME . ' | Base: ' . $this->source->get()->getName());

        $stats = call_user_func($this->strategy->get()::class . '::process', $this->db, $this->console, $this->strategy, $this->source);
        if (!$stats instanceof CombineStats) {
            throw new \RuntimeException(\sprintf(
                '%s::process() must return %s so the run can report coverage.',
                $this->strategy->get()::class,
                CombineStats::class,
            ));
        }

        return $stats;
    }
}
