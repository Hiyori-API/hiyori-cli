<?php

namespace Hiyori\Commands;

use Hiyori\Service\Publisher;
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
        $publisher = new Publisher(new Client($_ENV['MONGODB_URL']));

        $io->writeln('Building relational dump…');
        $result = $publisher->dump(!$input->getOption('no-gzip'), !$input->getOption('no-manga'));
        ['written' => $written, 'rows' => $rows, 'dir' => $dir] = $result;

        $io->newLine();
        $io->writeln('Dump written:');
        foreach ($written as $file => $count) {
            $io->writeln(sprintf('  %-22s %s', $file, $count . ($file === 'schema.sql' || $file === 'README.md' ? ' bytes' : ' rows')));
        }
        $io->writeln(sprintf('  %-22s %s', 'relational rows', (string) $rows));

        $repo = $input->getOption('repo');
        if ($input->getOption('dry-run')) {
            $io->newLine();
            $io->note(sprintf('Dry run — no push. Publish would commit %s/* to %s (%s).', $dir, $repo, $input->getOption('branch')));
            $io->success('Dump ready at ' . $dir);

            return Command::SUCCESS;
        }

        // Real publish: clone/copy -> commit -> push
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
            @mkdir($cloneDir, 0777, true);
            exec('cd ' . escapeshellarg($cloneDir) . ' && git init -q');
        }

        exec('cp -R ' . escapeshellarg(rtrim($dumpDir, '/') . '/.') . ' ' . escapeshellarg($cloneDir) . ' 2>&1');
        $summary = 'hiyori publish: relational dump ' . basename($dumpDir);
        $msg = $message !== '' ? $message : $summary;
        exec(
            'cd ' . escapeshellarg($cloneDir) . ' && git add -A && git -c user.name="hiyori-bot" -c user.email="hiyori@hikarino.local" commit -m ' . escapeshellarg($msg) . ' 2>&1',
            $o,
            $rc
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
