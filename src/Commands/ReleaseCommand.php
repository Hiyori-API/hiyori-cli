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

/**
 * Tier B compressed release pipeline.
 *
 * Builds the dump, verifies its manifest, produces deterministic
 * `.tar.zst` / `.jsonl.zst` assets, writes `SHA256SUMS.txt` and a
 * `.hiyori-release/latest.json` pointer, then publishes assets to a GitHub
 * release (when `gh` is available) or prints the fallback commands.
 */
#[AsCommand(
    name: 'release',
    description: 'Build, verify and publish a compressed Tier B release dataset',
    aliases: ['db:release'],
)]
final class ReleaseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'Target repo (owner/repo, URL, or local dir)', getenv('GITHUB_REPO') ?: 'Hiyori-API/HiyoriDB')
            ->addOption('branch', 'b', InputOption::VALUE_OPTIONAL, 'Pointer branch in the target repo', 'master')
            ->addOption('tag', null, InputOption::VALUE_OPTIONAL, 'Release/tag name (default: data-YYYY.WW)', '')
            ->addOption('profile', null, InputOption::VALUE_OPTIONAL, 'Export profile', ExportConfig::DEFAULT_PROFILE)
            ->addOption('no-manga', null, InputOption::VALUE_NONE, 'Exclude manga')
            ->addOption('no-zstd', null, InputOption::VALUE_NONE, 'Skip the .tar.zst archive (assets only)')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Create the GitHub release as a draft')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Build + verify assets, but do not publish')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Verify manifest checksums before packaging (always on; kept for symmetry)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Publish even when the content hash is unchanged')
            ->addOption('clean-target', null, InputOption::VALUE_NONE, 'Remove the legacy raw tree (data/, index/, manifest.json, CHANGELOG.md, tarballs) from the target branch')
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'Release notes message override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tagOption = (string) $input->getOption('tag');
        $calendarTag = 'data-' . (new \DateTimeImmutable())->format('o.W');
        $force = (bool) $input->getOption('force');
        $stateFile = ExportConfig::rootDir() . '/storage/state/release-latest.json';
        $previous = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
        $previous = is_array($previous) ? $previous : null;

        $config = ExportConfig::load();
        $config = ExportConfig::merge($config, [
            'entities' => ['manga' => !$input->getOption('no-manga') && (bool) ($config['entities']['manga'] ?? true)],
            'bundle' => false,
        ]);
        $config = ExportConfig::withProfile($config, (string) $input->getOption('profile'));

        $exporter = new Exporter(new Client($_ENV['MONGODB_URL']));
        $io->writeln('Building dump…');
        $result = $exporter->write($config);

        $verification = Exporter::verify($result['dir']);
        if (!$verification['ok']) {
            foreach ($verification['issues'] as $issue) {
                $io->error('verify: ' . $issue);
            }

            return Command::FAILURE;
        }
        $io->writeln(sprintf('  verify ok: %d files, %s', $verification['checked'], (string) $verification['content_hash']));

        $contentHash = (string) $verification['content_hash'];
        $revision = (int) ($previous['revision'] ?? 0);
        if ($tagOption !== '') {
            $tag = $tagOption;
        } elseif ($previous !== null && ($previous['content_hash'] ?? null) === $contentHash && !$force) {
            $io->success('Content unchanged (' . $contentHash . ') — release ' . ($previous['tag'] ?? '') . ' is already current. Use --force to republish.');
            $io->writeln('  pointer: .hiyori-release/latest.json');

            return Command::SUCCESS;
        } else {
            if ($previous !== null && ($previous['content_hash'] ?? null) !== $contentHash) {
                $revision++;
            }
            $tag = $revision > 0 ? $calendarTag . '.' . $revision : $calendarTag;
        }

        $releaseDir = dirname($result['dir']) . '/release-' . $tag;
        if (!is_dir($releaseDir) && !mkdir($releaseDir, 0o777, true) && !is_dir($releaseDir)) {
            $io->error('Cannot create release dir: ' . $releaseDir);

            return Command::FAILURE;
        }

        try {
            $assets = $this->buildAssets($result['dir'], $releaseDir, $tag, !$input->getOption('no-zstd'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $pointer = $this->writePointer($releaseDir, $tag, $result['dir'], $contentHash, $assets, $revision);
        $io->writeln(sprintf('  pointer: %s', $pointer));
        foreach ($assets as $name => $bytes) {
            $io->writeln(sprintf('  asset: %-48s %s', $name, $this->human($bytes)));
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — assets built and verified, nothing published.');
            $io->writeln('  release dir: ' . $releaseDir);

            return Command::SUCCESS;
        }

        $repo = (string) $input->getOption('repo');
        $notesFile = $releaseDir . '/release-notes.md';
        $message = (string) ($input->getOption('message') ?? '');
        $this->writeNotes($notesFile, $tag, $result, $verification['content_hash'], $assets, $message);

        if ($this->ghAvailable()) {
            $ok = $this->publishWithGh($repo, $tag, $notesFile, $releaseDir, (bool) $input->getOption('draft'));
            if ($ok) {
                $this->recordState($stateFile, $tag, $revision, $contentHash);
                $io->success('Published release ' . $tag . ' to ' . $repo);

                // The pointer is how the target branch says where the current
                // release is, so it has to be committed even when the assets
                // went out as a GitHub Release. Returning before this left the
                // branch pointing at nothing.
                $pushed = $this->pushPointer($repo, (string) $input->getOption('branch'), $releaseDir, (bool) $input->getOption('clean-target'));
                if ($pushed) {
                    $io->success('Pointer committed to ' . $repo);
                } else {
                    $io->note('Pointer was not pushed (missing token/remote or local-only target).');
                }

                return Command::SUCCESS;
            }
            $io->warning('gh publish failed — falling back to pointer push.');
        }

        $io->writeln('gh is unavailable. Release assets are ready in ' . $releaseDir);
        $ghRepo = $this->normalizeRepo($repo);
        $io->writeln('  gh release create ' . $tag . ' --repo ' . $ghRepo . ' --title ' . escapeshellarg($tag) . ' --notes-file ' . escapeshellarg($notesFile));
        foreach (array_keys($assets) as $name) {
            $io->writeln('  gh release upload ' . $tag . ' ' . escapeshellarg($releaseDir . '/' . $name) . ' --repo ' . $ghRepo);
        }

        $pushed = $this->pushPointer($repo, (string) $input->getOption('branch'), $releaseDir, (bool) $input->getOption('clean-target'));
        if ($pushed) {
            $this->recordState($stateFile, $tag, $revision, $contentHash);
            $io->success('Pointer committed to ' . $repo);
        } else {
            $io->note('Pointer was not pushed (missing token/remote or local-only target).');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int> asset filename => bytes
     */
    private function buildAssets(string $dumpDir, string $releaseDir, string $tag, bool $tarzst): array
    {
        $assets = [];

        $manifest = $releaseDir . '/manifest.json';
        copy($dumpDir . '/manifest.json', $manifest);
        $assets['manifest.json'] = (int) filesize($manifest);

        if ($tarzst) {
            $archive = $releaseDir . '/hiyori-db-' . $tag . '.tar.zst';
            $epoch = (int) (getenv('SOURCE_DATE_EPOCH') ?: 0);
            $cmd = sprintf(
                'tar --sort=name --mtime=@%d --owner=0 --group=0 --numeric-owner -C %s -cf - . | zstd -q -19 -o %s',
                $epoch,
                escapeshellarg($dumpDir),
                escapeshellarg($archive),
            );
            @exec($cmd, $o, $rc);
            if (is_file($archive) && $this->archiveTest($archive)) {
                $assets['hiyori-db-' . $tag . '.tar.zst'] = (int) filesize($archive);
            } elseif (is_file($archive)) {
                @unlink($archive);
                throw new \RuntimeException('Archive integrity test failed: ' . $archive);
            }
        }

        foreach (['anime' => 'anime.jsonl', 'manga' => 'manga.jsonl'] as $kind => $file) {
            $source = $dumpDir . '/data/' . $file;
            if (!is_file($source)) {
                continue;
            }
            $target = $releaseDir . '/hiyori-db-' . $tag . '.' . $file . '.zst';
            @exec('zstd -q -19 -o ' . escapeshellarg($target) . ' ' . escapeshellarg($source), $o, $rc);
            if (is_file($target) && $this->archiveTest($target)) {
                $assets['hiyori-db-' . $tag . '.' . $file . '.zst'] = (int) filesize($target);
            } elseif (is_file($target)) {
                @unlink($target);
            }
        }

        // Optional archival sidecars.
        if (is_dir($dumpDir . '/data/tables')) {
            $tablesZip = $releaseDir . '/hiyori-db-' . $tag . '.tables.zip';
            @exec('cd ' . escapeshellarg($dumpDir) . ' && zip -qr ' . escapeshellarg($tablesZip) . ' data/tables', $o, $rc);
            if (is_file($tablesZip)) {
                $assets['hiyori-db-' . $tag . '.tables.zip'] = (int) filesize($tablesZip);
            }
        }
        if (is_file($dumpDir . '/schema.sql')) {
            $schemaZip = $releaseDir . '/hiyori-db-' . $tag . '.schema.zip';
            @exec('zip -qj ' . escapeshellarg($schemaZip) . ' ' . escapeshellarg($dumpDir . '/schema.sql'), $o, $rc);
            if (is_file($schemaZip)) {
                $assets['hiyori-db-' . $tag . '.schema.zip'] = (int) filesize($schemaZip);
            }
        }

        $assets = $this->splitLargeAssets($releaseDir, $assets);

        $sums = '';
        ksort($assets);
        foreach ($assets as $name => $_) {
            $sums .= hash_file('sha256', $releaseDir . '/' . $name) . '  ' . $name . "\n";
        }
        file_put_contents($releaseDir . '/SHA256SUMS.txt', $sums);
        $assets['SHA256SUMS.txt'] = (int) filesize($releaseDir . '/SHA256SUMS.txt');

        return $assets;
    }

    /**
     * @param array<string, int> $assets
     */
    private function writePointer(string $releaseDir, string $tag, string $dumpDir, string $contentHash, array $assets, int $revision = 0): string
    {
        $manifest = json_decode((string) file_get_contents($dumpDir . '/manifest.json'), true);
        $git = trim((string) @shell_exec('git -C ' . escapeshellarg(ExportConfig::rootDir()) . ' rev-parse HEAD 2>/dev/null'));
        $pointer = [
            'tag' => $tag,
            'revision' => $revision,
            'content_hash' => $contentHash,
            'counts' => $manifest['counts'] ?? [],
            'generated_at' => $manifest['generated_at'] ?? date('c'),
            'source_commit' => $git,
            'assets' => array_keys($assets),
            'verify' => 'php hiyori publish --verify',
            'release_dir' => basename($releaseDir),
        ];
        $pointerDir = $releaseDir . '/.hiyori-release';
        if (!is_dir($pointerDir)) {
            mkdir($pointerDir, 0o777, true);
        }
        file_put_contents($pointerDir . '/latest.json', json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return '.hiyori-release/latest.json';
    }

    /**
     * @param array<string, int> $assets
     */
    private function writeNotes(string $path, string $tag, array $result, string $contentHash, array $assets, string $message): void
    {
        $pointer = json_decode((string) @file_get_contents(dirname($path) . '/.hiyori-release/latest.json'), true);
        $sourceCommit = is_array($pointer) ? (string) ($pointer['source_commit'] ?? '') : '';
        $lines = [
            '# HiyoriDB release ' . $tag,
            '',
            $message !== '' ? $message : 'Automated Tier B release.',
            '',
            '- **content_hash:** `' . $contentHash . '`',
            '- **source_commit:** `' . ($sourceCommit !== '' ? $sourceCommit : 'unknown') . '`',
            '- **files:** ' . $result['files'] . ' (`+' . $result['changelog']['added'] . ' ~' . $result['changelog']['updated'] . ' -' . $result['changelog']['removed'] . '`)',
            '- **rows:** ' . $result['rows'],
            '',
            '## Assets',
            '',
        ];
        foreach ($assets as $name => $bytes) {
            $lines[] = '- `' . $name . '` (' . $this->human($bytes) . ')';
        }
        $lines[] = '';
        $lines[] = '## Verify';
        $lines[] = '';
        $lines[] = '```sh';
        $lines[] = 'sha256sum -c SHA256SUMS.txt';
        $lines[] = '```';
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private function ghAvailable(): bool
    {
        @exec('command -v gh 2>/dev/null', $o, $rc);

        return $rc === 0;
    }

    /**
     * @return bool success
     */
    private function publishWithGh(string $repo, string $tag, string $notesFile, string $releaseDir, bool $draft): bool
    {
        $repoArg = escapeshellarg($this->normalizeRepo($repo));
        $cmd = 'gh release create ' . escapeshellarg($tag)
            . ' --repo ' . $repoArg
            . ' --title ' . escapeshellarg('HiyoriDB ' . $tag)
            . ' --notes-file ' . escapeshellarg($notesFile)
            . ($draft ? ' --draft' : '');
        @exec($cmd . ' 2>&1', $o, $rc);
        if ($rc !== 0) {
            return false;
        }
        $files = glob($releaseDir . '/*') ?: [];
        $upload = [];
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== 'release-notes.md' && !str_starts_with(basename($file), '.hiyori-release')) {
                $upload[] = escapeshellarg($file);
            }
        }
        if ($upload !== []) {
            @exec('gh release upload ' . escapeshellarg($tag) . ' ' . implode(' ', $upload) . ' --repo ' . $repoArg . ' --clobber 2>&1', $o, $rc);
        }

        return $rc === 0;
    }

    private function pushPointer(string $repo, string $branch, string $releaseDir, bool $cleanTarget = false): bool
    {
        $token = getenv('HIYORI_DB_TOKEN') ?: (getenv('GITHUB_TOKEN') ?: '');
        if ($token === '' && !is_dir($repo)) {
            return false;
        }

        $clone = $releaseDir . '/pointer-repo';
        $cloneCmd = 'git clone --depth 1 -b ' . escapeshellarg($branch) . ' ' . escapeshellarg($repo) . ' ' . escapeshellarg($clone) . ' 2>&1';
        @exec($cloneCmd, $o, $rc);
        if (!is_dir($clone . '/.git')) {
            return false;
        }

        $dest = $clone . '/.hiyori-release';
        if (!is_dir($dest) && !mkdir($dest, 0o777, true) && !is_dir($dest)) {
            return false;
        }
        copy($releaseDir . '/.hiyori-release/latest.json', $dest . '/latest.json');
        copy($releaseDir . '/SHA256SUMS.txt', $clone . '/SHA256SUMS.txt');

        if ($cleanTarget) {
            foreach (['data', 'index', 'manifest.json', 'CHANGELOG.md'] as $legacy) {
                @exec('rm -rf ' . escapeshellarg($clone . '/' . $legacy));
            }
            foreach (glob($clone . '/*.tar.gz') ?: [] as $tarball) {
                @unlink($tarball);
            }
            foreach (glob($clone . '/*.tar.zst') ?: [] as $tarball) {
                @unlink($tarball);
            }
        }

        $msg = 'release: update .hiyori-release/latest.json';
        @exec('cd ' . escapeshellarg($clone) . ' && git add -A && git -c user.name="hiyori-bot" -c user.email="hiyori@hikarino.local" commit -m ' . escapeshellarg($msg) . ' 2>&1', $o, $rc);
        if ($rc !== 0) {
            return true; // Pointer already up to date.
        }

        if ($token !== '') {
            $remote = str_replace('https://github.com/', 'https://x-access-token:' . $token . '@github.com/', $repo);
        } else {
            $remote = 'origin';
        }
        @exec('cd ' . escapeshellarg($clone) . ' && git push ' . escapeshellarg($remote) . ' HEAD:' . escapeshellarg($branch) . ' 2>&1', $o, $rc);

        return $rc === 0;
    }

    private function recordState(string $stateFile, string $tag, int $revision, string $contentHash): void
    {
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        @file_put_contents($stateFile, json_encode([
            'tag' => $tag,
            'revision' => $revision,
            'content_hash' => $contentHash,
            'recorded_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Split assets above 1.9 GB so none exceeds the 2 GB release-asset cap.
     *
     * @param array<string, int> $assets
     *
     * @return array<string, int>
     */
    private function splitLargeAssets(string $releaseDir, array $assets): array
    {
        $limit = (int) floor(1.9 * 1024 * 1024 * 1024);
        $out = [];
        foreach ($assets as $name => $bytes) {
            if ($bytes <= $limit) {
                $out[$name] = $bytes;

                continue;
            }
            @exec('split -b 1900M ' . escapeshellarg($releaseDir . '/' . $name) . ' ' . escapeshellarg($releaseDir . '/' . $name . '.part-'), $o, $rc);
            $parts = glob($releaseDir . '/' . $name . '.part-*') ?: [];
            if ($parts === []) {
                $out[$name] = $bytes;

                continue;
            }
            @unlink($releaseDir . '/' . $name);
            foreach ($parts as $part) {
                $out[basename($part)] = (int) filesize($part);
            }
        }

        return $out;
    }

    private function archiveTest(string $archive): bool
    {
        if (str_ends_with($archive, '.zst')) {
            @exec('zstd -t ' . escapeshellarg($archive) . ' 2>/dev/null', $o, $rc);

            return $rc === 0;
        }

        return is_file($archive);
    }

    private function normalizeRepo(string $repo): string
    {
        if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$#', $repo, $m) === 1) {
            return $m[1];
        }

        return trim($repo, '/');
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2) . ' ' . $units[$i];
    }
}
