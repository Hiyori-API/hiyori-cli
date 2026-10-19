<?php

namespace Hiyori\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The release chain must have exactly one owner.
 *
 * The stage order used to live in three places at once — the CI workflow, the
 * shell scripts and the dashboard — each implementing a different subset.
 * `php hiyori pipeline:release` owns it now. These are contract tests over
 * the files that used to spell the order out a second time: they read those
 * files, and for the scripts they run them against a stubbed `php`, so no
 * database is touched and no release is published.
 */
final class ReleasePathWiringTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            $this->remove($path);
        }
        $this->tempPaths = [];
    }

    // ---------------------------------------------------------------- CI ----

    public function testTheWorkflowRunsTheOrchestratorExactlyOnce(): void
    {
        $code = $this->code(self::read('.github/workflows/publish.yml'));

        $this->assertSame(
            1,
            substr_count($code, 'php hiyori pipeline:release'),
            'the workflow must call the orchestrator once, not once per stage',
        );
    }

    public function testTheWorkflowDoesNotHandRollTheChainAgain(): void
    {
        $code = $this->code(self::read('.github/workflows/publish.yml'));

        foreach (['migrate', 'combine', 'combine:dupes', 'release'] as $command) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*php\s+hiyori\s+' . preg_quote($command, '/') . '\b/m',
                $code,
                sprintf('the workflow must not run `php hiyori %s` itself', $command),
            );
        }
    }

    public function testTheWorkflowKeepsTheReleaseGateVisible(): void
    {
        $workflow = self::read('.github/workflows/publish.yml');

        $this->assertStringContainsString(
            'Release gate (no duplicate identity keys)',
            $workflow,
            'the release gate must stay visible in the job output',
        );
    }

    public function testTheWorkflowKeepsTheReleaseSecrets(): void
    {
        $workflow = self::read('.github/workflows/publish.yml');

        foreach (['GH_TOKEN:', 'GITHUB_TOKEN:', 'GITHUB_REPO:'] as $key) {
            $this->assertStringContainsString($key, $workflow);
        }
    }

    // ------------------------------------------------------------ scripts ----

    public function testFinalizeMergesRunsTheCombineStagesThroughTheOrchestrator(): void
    {
        $script = self::read('scripts/finalize-merges.sh');

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('php hiyori pipeline:release', $script);
        $this->assertStringContainsString('--from=combine:anime', $script);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*php\s+hiyori\s+combine\b/m',
            $this->code($script),
            'the script must not run `php hiyori combine` itself',
        );
    }

    public function testFinalizeMergesCallsTheOrchestrator(): void
    {
        $sandbox = $this->sandbox();
        $log = $sandbox . '/stub.log';

        $code = $this->runScript($sandbox . '/scripts/finalize-merges.sh', ['STUB_LOG' => $log]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString(
            'php hiyori pipeline:release --from=combine:anime',
            $this->stubLog($log),
        );
    }

    public function testFinalizeMergesPropagatesTheOrchestratorExitCode(): void
    {
        $sandbox = $this->sandbox();
        $log = $sandbox . '/stub.log';

        $code = $this->runScript($sandbox . '/scripts/finalize-merges.sh', [
            'STUB_LOG' => $log,
            'STUB_EXIT' => '3',
        ]);

        $this->assertSame(
            3,
            $code,
            'a failed combine must fail the script instead of being logged as a normal end',
        );
        $this->assertStringContainsString(
            'FAILED',
            (string) file_get_contents($sandbox . '/storage/logs/finalize-merges.log'),
        );
    }

    public function testPublishReleaseRunsTheReleaseStageOfTheOrchestrator(): void
    {
        $sandbox = $this->sandbox();
        $log = $sandbox . '/stub.log';

        $code = $this->runScript($sandbox . '/scripts/publish-release.sh', ['STUB_LOG' => $log]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString(
            'php hiyori pipeline:release --from=release',
            $this->stubLog($log),
        );
    }

    public function testPublishReleaseRefusesAnOptionTheReleaseStageDoesNotCarry(): void
    {
        $sandbox = $this->sandbox();
        $log = $sandbox . '/stub.log';

        $code = $this->runScript($sandbox . '/scripts/publish-release.sh', [
            'STUB_LOG' => $log,
            'STUB_ARGS' => '--dry-run',
        ]);

        $this->assertNotSame(0, $code, 'an option the release stage cannot carry must fail');
        $this->assertSame(
            '',
            $this->stubLog($log),
            'the option must be refused before the orchestrator runs anything',
        );
    }

    // ---------------------------------------------------------- dashboard ----

    public function testTheDashboardCombineRouteStaysOnTheDirectCall(): void
    {
        $route = self::read('public/index.php');

        $this->assertStringNotContainsString(
            'hiyori pipeline:release',
            $route,
            'the dashboard route is an operator rebuild, not the release chain',
        );
        $this->assertStringContainsString(
            'php hiyori %s',
            $route,
            'the route still spawns `php hiyori combine` directly',
        );
        $this->assertStringContainsString(
            "' --weights '",
            $route,
            'the weights saved in Settings must still reach the combine',
        );
        $this->assertStringContainsString(
            "sendJson(['started' => true, 'base' => \$base, 'strategy' => \$strategy, 'pid' => \$pid, 'weights' => \$weights]);",
            $route,
            'the response shape the SPA reads must not change',
        );
    }

    // ------------------------------------------------------------- helpers ----

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The file without its comment lines: what a shell would actually run. */
    private function code(string $contents): string
    {
        $kept = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * A copy of the scripts in a scratch directory, with `php`, `pgrep` and
     * `mongosh` stubbed out.
     *
     * @return string the scratch directory
     */
    private function sandbox(): string
    {
        $dir = sys_get_temp_dir() . '/hiyori-wiring-' . bin2hex(random_bytes(6));
        mkdir($dir . '/scripts', 0o777, true);
        mkdir($dir . '/bin', 0o777, true);
        mkdir($dir . '/storage/logs', 0o777, true);
        $this->tempPaths[] = $dir;

        foreach (['finalize-merges.sh', 'publish-release.sh'] as $script) {
            copy(dirname(__DIR__, 2) . '/scripts/' . $script, $dir . '/scripts/' . $script);
        }

        file_put_contents($dir . '/bin/php', <<<'STUB'
            #!/usr/bin/env bash
            echo "php $*" >> "${STUB_LOG:-/dev/null}"
            exit "${STUB_EXIT:-0}"
            STUB);
        file_put_contents($dir . '/bin/pgrep', "#!/usr/bin/env bash\nexit 1\n");
        file_put_contents($dir . '/bin/mongosh', "#!/usr/bin/env bash\necho \"mongosh $*\"\n");
        foreach (['php', 'pgrep', 'mongosh'] as $stub) {
            chmod($dir . '/bin/' . $stub, 0o755);
        }

        return $dir;
    }

    /**
     * @param array<string, string> $env
     *
     * @return int the exit code
     */
    private function runScript(string $script, array $env = []): int
    {
        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= $key . '=' . escapeshellarg($value) . ' ';
        }

        $bin = dirname($script, 2) . '/bin';
        $command = sprintf(
            '%sPATH=%s bash %s',
            $prefix,
            escapeshellarg($bin . ':' . (getenv('PATH') ?: '/usr/bin:/bin')),
            escapeshellarg($script),
        );

        $args = trim((string) ($env['STUB_ARGS'] ?? ''));
        if ($args !== '') {
            $command .= ' ' . escapeshellarg($args);
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        return $code;
    }

    private function stubLog(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
