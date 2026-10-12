<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'health',
    description: 'Health check for services and dependencies',
    aliases: ['hc'],
    hidden: false
)]
final class HealthCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch mode (continuous checks)')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Watch interval in seconds (default: 30)', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = $input->getOption('json');
        $watch = $input->getOption('watch');
        $interval = (int) $input->getOption('interval');

        $healthCheck = $this->hiyori->getHealthCheck();
        $metrics = $this->hiyori->getMetrics();

        if ($watch) {
            $io->section('Health Watch Mode (Ctrl+C to stop)');
            $io->text("Interval: {$interval}s");
            
            while (true) {
                $result = $healthCheck->check();
                $this->displayResult($io, $result, $asJson);
                
                if (!$asJson) {
                    $io->text(str_repeat('-', 60));
                }
                
                sleep($interval);
            }
        } else {
            $result = $healthCheck->check();
            $this->displayResult($io, $result, $asJson);
            
            return $result['status'] === 'healthy' ? Command::SUCCESS : Command::FAILURE;
        }
    }

    private function displayResult(SymfonyStyle $io, array $result, bool $asJson): void
    {
        if ($asJson) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT));
            return;
        }

        $status = $result['status'];
        $timestamp = $result['timestamp'];
        
        $style = $status === 'healthy' ? 'info' : 'error';
        $io->section("Health Check - {$timestamp}");
        $io->writeln("<{$style}>Status: {$status}</{$style}>");

        foreach ($result['checks'] as $name => $check) {
            $checkStyle = ($check['healthy'] ?? false) ? 'info' : 'error';
            $checkStatus = ($check['healthy'] ?? false) ? '✓' : '✗';
            
            $io->writeln("  <{$checkStyle}>{$checkStatus} {$name}</{$checkStyle}>");
            
            if (isset($check['latency_ms'])) {
                $io->writeln("    Latency: {$check['latency_ms']}ms");
            }
            if (isset($check['endpoints'])) {
                foreach ($check['endpoints'] as $ep => $epResult) {
                    $epStyle = ($epResult['healthy'] ?? false) ? 'info' : 'error';
                    $epStatus = ($epResult['healthy'] ?? false) ? '✓' : '✗';
                    $latency = $epResult['latency_ms'] ?? 'N/A';
                    $io->writeln("    <{$epStyle}>{$epStatus} {$ep}</{$epStyle}> ({$latency}ms)");
                }
            }
            if (isset($check['limiters'])) {
                foreach ($check['limiters'] as $limiter => $stat) {
                    $state = $stat['circuit_state'] ?? 'unknown';
                    $tokens = $stat['tokens_available'] ?? 'N/A';
                    $io->writeln("    {$limiter}: {$state} (tokens: {$tokens})");
                }
            }
            if (isset($check['used_percent'])) {
                $io->writeln("    Used: {$check['used_percent']}%");
            }
            if (isset($check['error'])) {
                $io->writeln("    <error>Error: {$check['error']}</error>");
            }
        }
    }
}
