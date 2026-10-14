<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\ExportConfig;
use PHPUnit\Framework\TestCase;

final class ExportConfigTest extends TestCase
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

    /**
     * @param array<string, mixed> $data
     */
    private function tempJson(array $data): string
    {
        $path = sys_get_temp_dir() . '/hiyori-export-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, (string) json_encode($data));
        $this->files[] = $path;

        return $path;
    }

    public function testDefaultsResolveProfileDerivedKeys(): void
    {
        $config = ExportConfig::defaults();

        $this->assertSame('per-entry-json', $config['profile']);
        $this->assertSame('{subdir}/{kind}/{filename}.json', $config['layout']);
        $this->assertSame('json', $config['format']);
        $this->assertSame('data', $config['subdir']);
        $this->assertSame('{hiyori_id}-{slug}', $config['filename']);
        $this->assertTrue($config['entities']['anime']);
        $this->assertTrue($config['entities']['manga']);
        $this->assertFalse($config['include']['tags']);
        $this->assertTrue($config['include']['titles']);
        $this->assertTrue($config['pretty']);
        $this->assertTrue($config['index']);
        $this->assertTrue($config['bundle']);
        $this->assertNull($config['combiner']['auto_export']);
    }

    public function testStateOverridesTrackedFileAndTrackedOverridesDefaults(): void
    {
        $tracked = $this->tempJson([
            'profile' => 'bulk-jsonl',
            'subdir' => 'tracked-subdir',
            'include' => ['tags' => true],
        ]);
        $state = $this->tempJson([
            'profile' => 'tables-csv',
            'include' => ['tags' => false],
            'entities' => ['manga' => false],
        ]);

        $config = ExportConfig::load($state, $tracked);

        // state wins for scalars and nested keys
        $this->assertSame('tables-csv', $config['profile']);
        $this->assertFalse($config['include']['tags']);
        $this->assertFalse($config['entities']['manga']);
        // tracked survives where state is silent
        $this->assertSame('tracked-subdir', $config['subdir']);
        $this->assertTrue($config['include']['titles']);
        // layout/format follow the winning profile
        $this->assertSame('{subdir}/tables/{kind}.csv', $config['layout']);
        $this->assertSame('csv', $config['format']);
    }

    public function testMissingStateFallsBackToTrackedFile(): void
    {
        $tracked = $this->tempJson(['profile' => 'bulk-jsonl', 'filename' => '{slug}']);

        $config = ExportConfig::load(sys_get_temp_dir() . '/does-not-exist-export.json', $tracked);

        $this->assertSame('bulk-jsonl', $config['profile']);
        $this->assertSame('{slug}', $config['filename']);
        $this->assertSame('{subdir}/{kind}.jsonl', $config['layout']);
    }

    public function testExplicitProfileKeyBeatsProfileDefaults(): void
    {
        $config = ExportConfig::resolve(['profile' => 'bulk-jsonl', 'pretty' => true, 'index' => false]);

        $this->assertSame('bulk-jsonl', $config['profile']);
        $this->assertTrue($config['pretty']);
        $this->assertFalse($config['index']);
        $this->assertSame('jsonl', $config['format']);
        $this->assertFalse($config['write_entries']);
    }

    public function testUnknownProfileFallsBackToDefault(): void
    {
        $this->assertSame('per-entry-json', ExportConfig::resolve(['profile' => 'nope'])['profile']);
    }

    public function testOverridesApplyDotNotationWithTypes(): void
    {
        $config = ExportConfig::overrides(ExportConfig::defaults(), [
            'include.tags=true',
            'entities.manga=false',
            'pretty=false',
            'filename={hiyori_id}',
            'fields=title,type',
        ]);

        $this->assertTrue($config['include']['tags']);
        $this->assertFalse($config['entities']['manga']);
        $this->assertFalse($config['pretty']);
        $this->assertSame('{hiyori_id}', $config['filename']);
        $this->assertSame(['title', 'type'], $config['fields']);
    }

    public function testMergeReplacesListsButRecursesMaps(): void
    {
        $merged = ExportConfig::merge(
            ['fields' => ['a'], 'include' => ['tags' => false, 'links' => true]],
            ['fields' => ['b', 'c'], 'include' => ['tags' => true]],
        );

        $this->assertSame(['b', 'c'], $merged['fields']);
        $this->assertTrue($merged['include']['tags']);
        $this->assertTrue($merged['include']['links']);
    }

    public function testProfileForFormatBackCompat(): void
    {
        $this->assertSame('tables-csv', ExportConfig::profileForFormat('relational'));
        $this->assertSame('tables-csv', ExportConfig::profileForFormat('csv'));
        $this->assertSame('bulk-jsonl', ExportConfig::profileForFormat('jsonl'));
        $this->assertSame('per-entry-json', ExportConfig::profileForFormat('json'));
    }

    public function testProfilesAreRegistered(): void
    {
        $this->assertSame(['per-entry-json', 'bulk-jsonl', 'tables-csv'], ExportConfig::profileNames());
    }
}
