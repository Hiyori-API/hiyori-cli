<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\ExportConfig;
use Hiyori\Service\Exporter;
use PHPUnit\Framework\TestCase;

final class ExporterTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::removeTree($dir);
        }
        $this->dirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/hiyori-exporter-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function anime(): array
    {
        return [
            '_id' => ['$oid' => '6acd5cefeb712a640837e101'],
            'hiyori_id' => 1,
            'title' => 'Cowboy Bebop',
            'synonyms' => ['romaji' => 'Cowboy Bebop', 'native' => 'カウボーイビバップ', '0' => 'Kowboj Bebop'],
            'type' => 'tv',
            'episodes' => 26,
            'chapters' => null,
            'volumes' => null,
            'status' => 'finished',
            'season' => 'spring',
            'year' => 1998,
            'images' => ['https://cdn.example/bebop.png'],
            'reference_ids' => ['k' => '1', 'mal' => '1', 'al' => '1', 'zz' => '9'],
            'references' => ['0' => 'https://anilist.co/anime/1', '1' => 'https://myanimelist.net/anime/1', '2' => 'https://kitsu.io/anime/1/bebop'],
            'tags' => ['0' => 'space', '1' => 'crime'],
            'genres' => ['action', 'sci-fi'],
            'synopsis' => 'Bounty hunters in space.',
            'score' => 87,
            'studios' => ['Sunrise'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manga(): array
    {
        return [
            '_id' => ['$oid' => '6acd76b6eb712a640840797e'],
            'hiyori_id' => 2,
            'title' => 'MONSTER',
            'synonyms' => ['Monster', '몬스터'],
            'type' => 'manga',
            'chapters' => 162,
            'volumes' => 18,
            'status' => 'finished',
            'year' => 1994,
            'images' => ['https://cdn.example/monster.jpg'],
            // Consistent identity: before manga URL patterns existed, the
            // 'al_manga' id was never derived from the link, so this fixture
            // paired 'al_manga' => '19' with a link for a different id.
            'reference_ids' => ['al_manga' => '30001', 'mal_manga' => '1'],
            'references' => ['0' => 'https://anilist.co/manga/30001'],
        ];
    }

    public function testSlugifyAndFilenameRendering(): void
    {
        $this->assertSame('cowboy-bebop', Exporter::slugify('Cowboy Bebop'));
        $this->assertSame('fullmetal-alchemist-brotherhood', Exporter::slugify('Fullmetal Alchemist: Brotherhood!'));
        $this->assertSame('untitled', Exporter::slugify('###'));

        $long = Exporter::slugify(str_repeat('word ', 40));
        $this->assertLessThanOrEqual(120, strlen($long));
        $this->assertStringEndsNotWith('-', $long);

        $this->assertSame('1-cowboy-bebop', Exporter::renderFilename('{hiyori_id}-{slug}', 1, 'Cowboy Bebop'));
        $this->assertSame('42-untitled', Exporter::renderFilename('{hiyori_id}-{slug}', 42, '###'));
        $this->assertSame('cowboy-bebop', Exporter::renderFilename('{slug}', 1, 'Cowboy Bebop'));
    }

    public function testUniqueFilenameAddsCollisionSuffix(): void
    {
        $this->assertSame('1-cowboy-bebop', Exporter::uniqueFilename('1-cowboy-bebop', []));
        $this->assertSame('1-cowboy-bebop-2', Exporter::uniqueFilename('1-cowboy-bebop', ['1-cowboy-bebop' => true]));
        $this->assertSame('1-cowboy-bebop-3', Exporter::uniqueFilename('1-cowboy-bebop', [
            '1-cowboy-bebop' => true,
            '1-cowboy-bebop-2' => true,
        ]));
    }

    public function testRenderEntryHasStableKeyOrder(): void
    {
        $entry = Exporter::renderEntry($this->anime(), 'anime', ExportConfig::defaults());

        $this->assertSame([
            '$schema',
            'hiyori_id',
            'slug',
            'kind',
            'title',
            'titles',
            'synonyms',
            'type',
            'episodes',
            'status',
            'season',
            'year',
            'images',
            'references',
        ], array_keys($entry));

        $this->assertSame(1, $entry['hiyori_id']);
        $this->assertSame('cowboy-bebop', $entry['slug']);
        $this->assertSame('anime', $entry['kind']);
        $this->assertSame(26, $entry['episodes']);
        $this->assertArrayNotHasKey('chapters', $entry);
        $this->assertSame('Cowboy Bebop', $entry['titles']['default']);
        $this->assertSame('カウボーイビバップ', $entry['titles']['native']);
    }

    public function testRenderEntryResolvesLinksAndReferences(): void
    {
        $entry = Exporter::renderEntry($this->anime(), 'anime', ExportConfig::defaults());

        $this->assertSame(['al' => ['id' => '1', 'url' => 'https://anilist.co/anime/1'], 'k' => ['id' => '1', 'url' => 'https://kitsu.io/anime/1'], 'mal' => ['id' => '1', 'url' => 'https://myanimelist.net/anime/1'], 'zz' => ['id' => '9']], $entry['references']);

    }

    public function testSchemaPointerReflectsDumpDepth(): void
    {
        $entry = Exporter::renderEntry($this->anime(), 'anime', ExportConfig::defaults(), '../../schema/hiyori.schema.json');
        $this->assertSame('../../schema/hiyori.schema.json', $entry['$schema']);
    }

    public function testV2TogglesDefaultOffAndOnlyEmitWhenPresent(): void
    {
        $off = Exporter::renderEntry($this->anime(), 'anime', ExportConfig::defaults());
        foreach (['tags', 'genres', 'synopsis', 'score', 'studios'] as $field) {
            $this->assertArrayNotHasKey($field, $off);
        }

        $on = Exporter::renderEntry($this->anime(), 'anime', ExportConfig::overrides(ExportConfig::defaults(), [
            'include.tags=true',
            'include.genres=true',
            'include.synopsis=true',
            'include.score=true',
            'include.studios=true',
        ]));
        $this->assertSame(['space', 'crime'], $on['tags']);
        $this->assertSame(['action', 'sci-fi'], $on['genres']);
        $this->assertSame('Bounty hunters in space.', $on['synopsis']);
        $this->assertSame(87, $on['score']);
        $this->assertSame(['Sunrise'], $on['studios']);
    }

    public function testMangaUsesChaptersVolumesAndMangaTemplates(): void
    {
        $entry = Exporter::renderEntry($this->manga(), 'manga', ExportConfig::defaults());

        $this->assertArrayNotHasKey('episodes', $entry);
        $this->assertArrayNotHasKey('season', $entry);
        $this->assertSame(162, $entry['chapters']);
        $this->assertSame(18, $entry['volumes']);
        // al_manga resolves from the stored AniList link; mal_manga has no
        // link, so it still exercises the LINK_TEMPLATES fallback.
        $this->assertSame('https://anilist.co/manga/30001', $entry['references']['al_manga']['url']);
        $this->assertSame('https://myanimelist.net/manga/1', $entry['references']['mal_manga']['url']);
    }

    public function testFieldsAllowlistKeepsIntegrityKeys(): void
    {
        $entry = Exporter::renderEntry(
            $this->anime(),
            'anime',
            ExportConfig::overrides(ExportConfig::defaults(), ['fields=title,episodes']),
        );

        $this->assertSame(['$schema', 'hiyori_id', 'slug', 'kind', 'title', 'episodes'], array_keys($entry));
    }

    public function testPrettyPrinterUsesTwoSpaces(): void
    {
        $entry = Exporter::renderEntry($this->anime(), 'anime', ExportConfig::defaults());
        $pretty = Exporter::entryJson($entry, true);

        $this->assertStringStartsWith("{\n  \"\$schema\"", $pretty);
        $this->assertStringContainsString("\n  \"hiyori_id\": 1,", $pretty);
        $this->assertStringContainsString("\n  \"references\": {\n    \"al\": {\n      \"id\": \"1\",\n      \"url\": \"https://anilist.co/anime/1\"", $pretty);
        $this->assertStringNotContainsString("\n    \"\$schema\"", $pretty);

        $compact = Exporter::entryJson($entry, false);
        $this->assertStringNotContainsString("\n", $compact);
    }

    public function testWriteIfChangedOnlyTouchesChangedFiles(): void
    {
        $path = $this->tempDir() . '/nested/entry.json';

        $this->assertTrue(Exporter::writeIfChanged($path, "{\n}\n"));
        $this->assertFileExists($path);
        $this->assertFalse(Exporter::writeIfChanged($path, "{\n}\n"));
        $this->assertTrue(Exporter::writeIfChanged($path, "{\n  \"a\": 1\n}\n"));
        $this->assertSame("{\n  \"a\": 1\n}\n", file_get_contents($path));
    }

    public function testManifestShapeAndContentHash(): void
    {
        $files = [
            'data/anime/1-cowboy-bebop.json' => ['sha256' => str_repeat('a', 64), 'bytes' => 120, 'rows' => 1],
            'data/anime.jsonl' => ['sha256' => str_repeat('b', 64), 'bytes' => 100, 'rows' => 1],
        ];

        $manifest = Exporter::buildManifest($files, ['anime' => 1, 'manga' => 0], '2026-01-02T03:04:05+00:00', 'per-entry-json');

        $this->assertSame('per-entry-json', $manifest['profile']);
        $this->assertSame('2026-01-02T03:04:05+00:00', $manifest['generated_at']);
        $this->assertSame(1, $manifest['counts']['anime']);
        $this->assertSame(0, $manifest['counts']['manga']);
        $this->assertSame(2, $manifest['counts']['files']);
        $this->assertSame(2, $manifest['counts']['rows']);
        $this->assertStringStartsWith('sha256:', $manifest['content_hash']);
        $this->assertSame(71, strlen($manifest['content_hash']));
        $this->assertSame(str_repeat('a', 64), $manifest['files']['data/anime/1-cowboy-bebop.json']['sha256']);

        // Deterministic for identical input, and sensitive to checksum changes.
        $again = Exporter::buildManifest($files, ['anime' => 1], '2026-01-02T03:04:05+00:00', 'per-entry-json');
        $this->assertSame($manifest['content_hash'], $again['content_hash']);

        $changed = $files;
        $changed['data/anime/1-cowboy-bebop.json']['sha256'] = str_repeat('c', 64);
        $this->assertNotSame($manifest['content_hash'], Exporter::buildManifest($changed, ['anime' => 1], 'now', 'per-entry-json')['content_hash']);
    }

    public function testDiffManifestsCountsAddedUpdatedRemoved(): void
    {
        $previous = [
            'a.json' => ['sha256' => 'h1'],
            'b.json' => ['sha256' => 'h2'],
            'gone.json' => ['sha256' => 'h9'],
        ];
        $current = [
            'a.json' => ['sha256' => 'h1'],
            'b.json' => ['sha256' => 'hX'],
            'c.json' => ['sha256' => 'h3'],
        ];

        $diff = Exporter::diffManifests($previous, $current);

        $this->assertSame(1, $diff['added']);
        $this->assertSame(1, $diff['updated']);
        $this->assertSame(1, $diff['removed']);
        $this->assertSame(['c.json'], $diff['files']['added']);
        $this->assertSame(['b.json'], $diff['files']['updated']);
        $this->assertSame(['gone.json'], $diff['files']['removed']);
    }

    public function testDiffAgainstNoManifestMarksEverythingAdded(): void
    {
        $diff = Exporter::diffManifests(null, ['a.json' => ['sha256' => 'h1'], 'b.json' => ['sha256' => 'h2']]);
        $this->assertSame(2, $diff['added']);
        $this->assertSame(0, $diff['updated']);
        $this->assertSame(0, $diff['removed']);
    }

    public function testEstimateCountsKindsAndCapsSamples(): void
    {
        $entities = ['anime' => [], 'manga' => [$this->manga()]];
        for ($i = 1; $i <= 12; $i++) {
            $doc = $this->anime();
            $doc['hiyori_id'] = $i;
            $doc['title'] = 'Show ' . $i;
            $entities['anime'][] = $doc;
        }

        $estimate = Exporter::estimate($entities, ExportConfig::defaults());

        $this->assertSame(12, $estimate['byKind']['anime']);
        $this->assertSame(1, $estimate['byKind']['manga']);
        $this->assertSame(13, $estimate['files']);
        $this->assertCount(10, $estimate['sampleFilenames']);
        $this->assertGreaterThan(0, $estimate['bytesEstimate']);
    }

    public function testEmitWritesFullDumpShape(): void
    {
        $dir = $this->tempDir() . '/hiyori-relational-test';
        $config = ExportConfig::overrides(ExportConfig::defaults(), ['bundle=false']);
        $exporter = new Exporter(null, 'hiyori', $this->tempDir());

        $result = $exporter->emit(['anime' => [$this->anime()], 'manga' => [$this->manga()]], $config, $dir);

        $this->assertSame($dir, $result['dir']);
        $this->assertFileExists($dir . '/data/anime/1-cowboy-bebop.json');
        $this->assertFileExists($dir . '/data/manga/2-monster.json');
        $this->assertFileExists($dir . '/data/anime.jsonl');
        $this->assertFileExists($dir . '/data/tables/anime.csv');
        $this->assertFileExists($dir . '/data/tables/entity_references.csv');
        $this->assertFileExists($dir . '/index/lookup.json');
        $this->assertFileExists($dir . '/manifest.json');
        $this->assertFileExists($dir . '/schema.sql');
        $this->assertFileExists($dir . '/CHANGELOG.md');
        // CHANGELOG.md is written after the diff, so it is excluded from the count.
        $this->assertSame($result['files'] - 1, $result['changelog']['added']);

        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['counts']['anime']);
        $this->assertSame(1, $manifest['counts']['manga']);

        $lookup = json_decode((string) file_get_contents($dir . '/index/lookup.json'), true);
        $this->assertIsArray($lookup);
        $this->assertSame('cowboy-bebop', $lookup['1']['slug']);
        $this->assertSame('anime', $lookup['1']['kind']);
        $this->assertSame('1', $lookup['1']['refs']['k']);

        $entry = json_decode((string) file_get_contents($dir . '/data/anime/1-cowboy-bebop.json'), true);
        $this->assertIsArray($entry);
        $this->assertSame(1, $entry['hiyori_id']);
        $this->assertSame('../../schema/hiyori.schema.json', $entry['$schema']);
    }

    public function testVerifyAcceptsIntactDump(): void
    {
        $base = $this->tempDir();
        $dir = $base . '/verified-dump';
        $config = ExportConfig::overrides(ExportConfig::defaults(), ['bundle=false']);
        (new Exporter(null, 'hiyori', $base))->emit(['anime' => [$this->anime()], 'manga' => []], $config, $dir);

        $verification = Exporter::verify($dir);

        $this->assertTrue($verification['ok']);
        $this->assertGreaterThan(0, $verification['checked']);
        $this->assertStringStartsWith('sha256:', (string) $verification['content_hash']);
    }

    public function testVerifyDetectsTamperedFile(): void
    {
        $base = $this->tempDir();
        $dir = $base . '/tampered-dump';
        $config = ExportConfig::overrides(ExportConfig::defaults(), ['bundle=false']);
        (new Exporter(null, 'hiyori', $base))->emit(['anime' => [$this->anime()], 'manga' => []], $config, $dir);

        file_put_contents($dir . '/data/anime.jsonl', "tampered\n");
        $verification = Exporter::verify($dir);

        $this->assertFalse($verification['ok']);
        $this->assertContains('data/anime.jsonl', $verification['mismatched']);
    }

    public function testEmitTablesCsvProfileSkipsPerEntryFiles(): void
    {
        $dir = $this->tempDir() . '/tables-dump';
        $config = ExportConfig::overrides(ExportConfig::defaults(), ['profile=tables-csv', 'bundle=false']);
        $exporter = new Exporter(null, 'hiyori', $this->tempDir());

        $result = $exporter->emit(['anime' => [$this->anime()], 'manga' => []], $config, $dir);

        $this->assertFileDoesNotExist($dir . '/data/anime/1-cowboy-bebop.json');
        $this->assertFileExists($dir . '/data/tables/anime.csv');
        $this->assertFileExists($dir . '/data/anime.jsonl');
        $this->assertSame('tables-csv', $config['profile']);
        $this->assertGreaterThan(0, $result['rows']);
    }
}
