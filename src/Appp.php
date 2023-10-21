<?php

namespace Hiyori;

use Hiyori\Sources\SourceConfig;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Appp
{
    public $ingestion;

    private Client $client;
    private Serializer $serializer;
    private OutputInterface $output;
    private InputInterface $input;


    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->serializer = SerializerBuilder::create()->build();
        $this->client = new Client('mongodb://localhost:27017');
        $this->client->hiyori;
        $this->output = $output;
        $this->input = $input;
    }

    public function ingest(string $source): void
    {
        $this->ingestion = new $source(
            new SourceConfig($this->input, $this->output, $this->client, $this->serializer)
        );
    }
}