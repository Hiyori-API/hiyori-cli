<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Exception\ConfigurationException;
use Hiyori\Service\ExportConfig;
use PHPUnit\Framework\TestCase;

/**
 * A malformed export config file used to read as "no configuration", which
 * silently produced a default export. A missing file is still not an error —
 * there is a difference between "no state" and "broken state".
 */
final class ExportConfigFileTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/hiyori-export-file-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    public function testAMalformedStateFileIsAnError(): void
    {
        $state = $this->tempFile('{"profile": "bulk-jsonl"');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('export config: ' . $state . ' is not valid JSON');

        ExportConfig::load($state, null);
    }

    public function testTheParseErrorIsInTheMessage(): void
    {
        $state = $this->tempFile('not json at all');

        try {
            ExportConfig::load($state, null);
            $this->fail('a malformed file must be refused');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString($state, $e->getMessage());
            $this->assertStringContainsString('Syntax error', $e->getMessage());
        }
    }

    public function testAMalformedTrackedFileIsAnError(): void
    {
        $tracked = $this->tempFile('{"profile": ');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('export config: ' . $tracked . ' is not valid JSON');

        ExportConfig::load(null, $tracked);
    }

    public function testAnEmptyFileIsAnError(): void
    {
        $state = $this->tempFile('');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('export config: ' . $state . ' is empty');

        ExportConfig::load($state, null);
    }

    public function testAFileThatIsNotAJsonObjectIsAnError(): void
    {
        $state = $this->tempFile('[1, 2, 3]');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must contain a JSON object');

        ExportConfig::load($state, null);
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $missing = sys_get_temp_dir() . '/hiyori-export-missing-' . bin2hex(random_bytes(6)) . '.json';

        $config = ExportConfig::load($missing, null);

        $this->assertSame(ExportConfig::DEFAULT_PROFILE, $config['profile']);
        $this->assertSame('data', $config['subdir']);
    }

    public function testAMissingStateFileFallsBackToTheTrackedFile(): void
    {
        $tracked = $this->tempFile('{"profile": "bulk-jsonl", "filename": "{slug}"}');
        $missing = sys_get_temp_dir() . '/hiyori-export-missing-' . bin2hex(random_bytes(6)) . '.json';

        $config = ExportConfig::load($missing, $tracked);

        $this->assertSame('bulk-jsonl', $config['profile']);
        $this->assertSame('{slug}', $config['filename']);
    }

    public function testAValidFileIsStillRead(): void
    {
        $state = $this->tempFile('{"profile": "tables-csv", "include": {"tags": true}}');

        $config = ExportConfig::load($state, null);

        $this->assertSame('tables-csv', $config['profile']);
        $this->assertTrue($config['include']['tags']);
    }
}
