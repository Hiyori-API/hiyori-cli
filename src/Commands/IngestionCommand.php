<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ingest',
    description: 'Begins ingestion from selected source',
    aliases: ['i'],
    hidden: false
)]
final class IngestionCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Source to index')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL)
            ->addOption('update', 'u', InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->hiyori->ingest(
            $input->getArgument('source'),
            [
                'delay' => $input->getOption('delay') ?? 0,
                'update' => $input->getOption('update') ?? false
            ]
        );

        return Command::SUCCESS;
    }
}