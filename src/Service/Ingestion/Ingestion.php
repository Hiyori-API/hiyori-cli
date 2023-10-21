<?php

namespace Hiyori\Service\Ingestion;

use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;

class Ingestion
{
    public function __construct(
        private Database $db,
        private ConsoleFactory $console,
        private SourceConfigurationFactory $sources,
    )
    {
    }

    public function start()
    {
        $this->console->io()
            ->section("Ingestion: ".$this->sources->get()->getName());

        call_user_func($this->sources->get()->getIngestionService()."::process", $this->db, $this->console, $this->sources);
    }
}