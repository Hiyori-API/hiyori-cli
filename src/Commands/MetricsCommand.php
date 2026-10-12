<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'metrics',
    description: 'Expose Prometheus metrics',
    aliases: ['met'],
    hidden: false
)]
final class MetricsCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Expose Prometheus metrics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metrics = $this->hiyori->getMetrics();
        
        $output->write($metrics->render());
        
        return Command::SUCCESS;
    }
}
