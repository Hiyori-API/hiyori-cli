<?php

namespace Hiyori\Commands;

use Hiyori\Service\ExportConfig;
use Hiyori\Service\Exporter;
use MongoDB\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'publish',
    description: 'Dump the relational dataset and publish it to the HiyoriDB repository',
    aliases: ['db:push'],
)]
final class PublishCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'Target repo URL or local dir (default: Hiyori-API/HiyoriDB; or GITHUB_REPO env)', getenv('GITHUB_REPO') ?: 'https://github.com/Hiyori-API/HiyoriDB.git')
            ->addOption('branch', 'b', InputOption::VALUE_OPTIONAL, 'Branch to publish to', 'master')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only build the dump; print what would be pushed')
            ->addOption('no-gzip', null, InputOption::VALUE_NONE, 'Skip the .tar.gz bundle')
            ->addOption('no-manga', null, InputOption::VALUE_NONE, 'Exclude manga from the dump')
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'Commit message override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config = ExportConfig::load();
        $config = ExportConfig::merge($config, [
            'entities' => ['manga' => !$input->getOption('no-manga') && (bool) ($config['entities']['manga'] ?? true)],
            'bundle' => !$input->getOption('no-gzip'),
        ]);

        $exporter = new Exporter(new Client($_ENV['MONGODB_URL']));

        if ($input->getOption('dry-run')) {
            $estimate = $exporter->dryRun($config);
            $io->writeln(sprintf(
                'Dry run — profile %s: %d files (%d anime, %d manga), ~%d bytes',
                $config['profile'],
                $estimate['files'],
                $estimate['byKind']['anime'],
                $estimate['byKind']['manga'],
                $estimate['bytesEstimate'],
            ));
            foreach ($estimate['sampleFilenames'] as $sample) {
                $io->writeln('  ' . $sample);
            }
            $io->note('Dry run — no dump written, no push.');

            return Command::SUCCESS;
        }

        $io->writeln('Building relational dump…');
        $result = $exporter->write($config);
        ['written' => $written, 'rows' => $rows, 'dir' => $dir] = $result;

        $io->newLine();
        $io->writeln('Dump written:');
        foreach ($written as $file => $count) {
            $unit = str_ends_with((string) $file, '.csv') ? ' rows' : ' bytes';
            $io->writeln(sprintf('  %-34s %s', $file, $count . $unit));
        }
        $io->writeln(sprintf('  %-34s %s', 'relational rows', (string) $rows));
        $io->writeln(sprintf('  %-34s +%d ~%d -%d', 'changelog', $result['changelog']['added'], $result['changelog']['updated'], $result['changelog']['removed']));

        $repo = $input->getOption('repo');
        $publishDir = dirname($dir, 2) . '/repo-' . date('YmdHis');
        $ok = $this->gitPublish($repo, $input->getOption('branch'), $dir, $publishDir, (string) ($input->getOption('message') ?? ''));
        if (!$ok) {
            $io->error('Publish failed — see output above.');

            return Command::FAILURE;
        }
        $io->success('Published ' . $dir . ' → ' . $repo);

        return Command::SUCCESS;
    }

    private function gitPublish(string $repo, string $branch, string $dumpDir, string $cloneDir, string $message): bool
    {
        $token = getenv('GITHUB_TOKEN') ?: '';
        $auth = $token !== '' ? ' -c credential.helper= -c http.extraheader="Authorization: token ' . $token . '"' : '';

        @exec('git clone --depth 1 -b ' . escapeshellarg($branch) . ' ' . escapeshellarg($repo) . ' ' . escapeshellarg($cloneDir) . ' 2>&1', $o, $rc);
        if ($rc !== 0 && !is_dir($cloneDir . '/.git')) {
            // empty/remote-less repo fallback: init locally
            @mkdir($cloneDir, 0o777, true);
            exec('cd ' . escapeshellarg($cloneDir) . ' && git init -q');
        }

        exec('cp -R ' . escapeshellarg(rtrim($dumpDir, '/') . '/.') . ' ' . escapeshellarg($cloneDir) . ' 2>&1');
        $summary = 'hiyori publish: relational dump ' . basename($dumpDir);
        $msg = $message !== '' ? $message : $summary;
        exec(
            'cd ' . escapeshellarg($cloneDir) . ' && git add -A && git -c user.name="hiyori-bot" -c user.email="hiyori@hikarino.local" commit -m ' . escapeshellarg($msg) . ' 2>&1',
            $o,
            $rc,
        );
        if ($rc !== 0) {
            return false;
        }
        if ($token !== '') {
            $remote = str_replace('https://github.com/', 'https://x-access-token:' . $token . '@github.com/', $repo);
            exec('cd ' . escapeshellarg($cloneDir) . ' && git push ' . escapeshellarg($remote) . ' HEAD:' . escapeshellarg($branch) . ' 2>&1', $o, $rc);
        } else {
            exec('cd ' . escapeshellarg($cloneDir) . ' && git push origin HEAD:' . escapeshellarg($branch) . ' 2>&1', $o, $rc);
        }

        return $rc === 0;
    }
}
