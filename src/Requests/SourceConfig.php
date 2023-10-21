<?php

namespace Hiyori\Requests;

use JMS\Serializer\Serializer;
use MongoDB\Client;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SourceConfig
{
    public Serializer $serializer;
    public OutputInterface $output;

    public InputInterface $input;
    public Client $client;
    public SymfonyStyle $io;

    /**
     * @param Serializer $serializer
     * @param OutputInterface $output
     * @param Client $client
     */
    public function __construct(InputInterface $input, OutputInterface $output, Client $client, Serializer $serializer)
    {
        $this->serializer = $serializer;
        $this->output = $output;
        $this->client = $client;
        $this->input = $input;
        $this->io = new SymfonyStyle($this->input, $this->output);
    }
}