<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\ExportConfig;
use Hiyori\Service\Exporter;
use PHPUnit\Framework\TestCase;

/**
 * The emitter used to invent an id: 0 for whole entries and the row position for
 * filenames. A dump taken before `hiyori-id --backfill` therefore published
 * every entry as hiyori_id 0, with a lookup keyed by position, and reported
 * success. It now refuses.
 */
final class ExporterIdGuardTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/hiyori-id-guard-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    private function doc(?int $id): array
    {
        $doc = [
            '_id' => 'abc',
            'title' => 'Show',
            'synonyms' => [],
            'type' => 'anime',
            'status' => 'finished',
            'year' => 2000,
            'images' => [],
            'reference_ids' => [],
            'references' => [],
        ];
        if ($id !== null) {
            $doc['hiyori_id'] = $id;
        }

        return $doc;
    }

    public function testMissingAndZeroIdsAreReported(): void
    {
        $missing = Exporter::documentsMissingId(
            ['anime' => [$this->doc(1), $this->doc(null), $this->doc(0)]],
            ExportConfig::defaults(),
        );

        $this->assertCount(2, $missing);
    }

    public function testDisabledKindIsNotChecked(): void
    {
        $config = ExportConfig::overrides(ExportConfig::defaults(), ['entities.manga=false']);

        $this->assertSame([], Exporter::documentsMissingId(
            ['anime' => [], 'manga' => [$this->doc(null)]],
            $config,
        ));
    }

    public function testEmitRefusesToWriteWithoutIds(): void
    {
        $exporter = new Exporter(null, 'hiyori', $this->base);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hiyori-id --backfill');

        $exporter->emit(
            ['anime' => [$this->doc(null)], 'manga' => []],
            ExportConfig::defaults(),
            $this->base . '/out',
        );
    }
}
