<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dashboard:serve',
    description: 'Serve the live Hiyori dashboard (php -S based)',
    aliases: ['dashboard'],
    hidden: false,
)]
final class DashboardCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Bind address (default: 127.0.0.1)', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port (default: 8080)', '8080')
            ->addOption('token', 't', InputOption::VALUE_OPTIONAL, 'Optional access token; client must pass ?token= or X-Dashboard-Token header')
            ->addOption('no-open', null, InputOption::VALUE_NONE, 'Do not attempt to auto-open the browser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $token = (string) ($input->getOption('token') ?? '');
        $autoOpen = !$input->getOption('no-open');

        $router = dirname(__DIR__, 2) . '/public/index.php';
        if (!file_exists($router)) {
            $io->error('public/index.php not found — dashboard assets missing.');

            return Command::FAILURE;
        }

        $phpBin = PHP_BINARY;
        $cmd = [$phpBin, '-S', $host . ':' . $port, $router];

        $env = $_ENV;
        if ($token !== '') {
            $env['DASHBOARD_TOKEN'] = $token;
        }

        $proc = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], // stdout -> we echo server output
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2), $env);

        if (!is_resource($proc)) {
            $io->error('Failed to start PHP built-in server.');

            return Command::FAILURE;
        }

        $url = sprintf('http://%s:%d/', $host === '0.0.0.0' ? 'localhost' : $host, $port);
        $io->success('Dashboard serving at ' . $url);
        if ($token !== '') {
            $io->writeln('Token required: ?token=... or X-Dashboard-Token header');
        }
        $io->writeln('Endpoints: /api/status  /api/counts  /api/jobs  /api/health  /api/validate  /metrics');
        $io->writeln('Press Ctrl+C to stop.');

        if ($autoOpen && $host !== '0.0.0.0' && getenv('DISPLAY') !== false) {
            // best-effort open on Linux desktops
            @exec(sprintf('xdg-open %s >/dev/null 2>&1 &', escapeshellarg($url)));
        }

        // Stream server output to stdout/stderr until Ctrl+C
        $status = 0;
        $stdout = $pipes[1];
        $stderr = $pipes[2];
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        while (true) {
            $out = stream_get_contents($stdout);
            if ($out !== false && $out !== '') {
                $output->write($out);
            }
            $err = stream_get_contents($stderr);
            if ($err !== false && $err !== '') {
                $output->write('<fg=red>' . $err . '</>');
            }
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $status = $st['exitcode'];
                break;
            }
            usleep(100_000);
        }

        proc_close($proc);

        return $status === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
