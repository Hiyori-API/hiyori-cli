<?php

namespace Hiyori\Service;

use Hiyori\Models\Common\Identifiers\Identifiers;
use MongoDB\Client;

/**
 * Configurable dump engine for HiyoriDB.
 *
 * Renders `organized` / `organized_manga` documents into a deterministic,
 * git-friendly publish tree: per-entry JSON (or a JSONL bulk mirror / CSV
 * tables), a lookup index, per-file checksums in `manifest.json`, a schema
 * copy and a `CHANGELOG.md` diffed against the previous manifest.
 *
 * The pure `render*` / `build*` helpers take plain arrays so they can be unit
 * tested without a MongoDB connection.
 */
final class Exporter
{
    /** Relative `$schema` pointer used when the emitter cannot infer depth. */
    public const DEFAULT_SCHEMA_POINTER = '../../schema/hiyori.schema.json';

    public const SCHEMA_RELATIVE = 'schema/hiyori.schema.json';

    /**
     * Fallback URL templates for reference shorthands (used when a reference
     * id has no resolvable source URL in the document). Mirrors the
     * Identifier value objects; manga variants included.
     *
     * @var array<string, string>
     */
    private const LINK_TEMPLATES = [
        'al' => 'https://anilist.co/anime/%s',
        'al_manga' => 'https://anilist.co/manga/%s',
        'k' => 'https://kitsu.io/anime/%s',
        'k_manga' => 'https://kitsu.io/manga/%s',
        'mal' => 'https://myanimelist.net/anime/%s',
        'mal_manga' => 'https://myanimelist.net/manga/%s',
        'anidb' => 'https://anidb.net/anime/%s',
        'ann' => 'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=%s',
        'b' => 'https://bangumi.tv/subject/%s',
        'cr' => 'https://www.crunchyroll.com/series/%s',
        'fm' => 'https://www.funimation.com/shows/%s',
        'nf' => 'https://www.netflix.com/title/%s',
        'sb' => 'https://cal.syoboi.jp/tid/%s',
        't' => 'https://trakt.tv/shows/%s',
        'ttv' => 'https://tubitv.com/series/%s',
        'tvt' => 'http://www.tv-tokyo.co.jp/anime/%s',
        'tx' => 'https://twitter.com/%s',
        'wpen' => 'https://en.wikipedia.org/wiki/%s',
        'wpja' => 'https://ja.wikipedia.org/wiki/%s',
        'yt' => 'https://www.youtube.com/watch?v=%s',
        'ap' => 'https://www.amazon.com/gp/video/detail/%s',
    ];

    private string $outBase;

    public function __construct(
        private ?Client $client = null,
        private string $database = 'hiyori',
        ?string $outBase = null,
    ) {
        $this->outBase = $outBase ?? ExportConfig::rootDir() . '/storage/publish';
    }

    // ---------------------------------------------------------------------
    // Required interface
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     *
     * @return array{files: int, byKind: array{anime: int, manga: int}, sampleFilenames: list<string>, bytesEstimate: int}
     */
    public function dryRun(array $config): array
    {
        $config = ExportConfig::resolve($config);

        return self::estimate($this->fetchEntities($config), $config);
    }

    /**
     * Render the first document of a kind without touching disk.
     *
     * @param array<string, mixed> $config
     *
     * @return array{kind: string, filename: string|null, entry: array<string, mixed>|null, json: string|null}
     */
    public function preview(array $config, string $kind = 'anime'): array
    {
        $config = ExportConfig::resolve($config);
        $kind = $kind === 'manga' ? 'manga' : 'anime';

        $doc = null;
        if ($this->client !== null) {
            $collection = $kind === 'manga' ? 'organized_manga' : 'organized';
            $raw = $this->client->selectDatabase($this->database)->selectCollection($collection)->findOne();
            if ($raw !== null) {
                $decoded = json_decode(json_encode($raw), true);
                if (is_array($decoded)) {
                    $doc = $decoded;
                }
            }
        }
        if ($doc === null) {
            return ['kind' => $kind, 'filename' => null, 'entry' => null, 'json' => null];
        }

        $id = self::resolveId($doc, 1);
        $title = is_string($doc['title'] ?? null) ? $doc['title'] : self::scalar($doc['title'] ?? '');
        $entry = self::renderEntry($doc, $kind, $config);

        return [
            'kind' => $kind,
            'filename' => self::renderFilename((string) $config['filename'], $id, $title),
            'entry' => $entry,
            'json' => self::entryJson($entry, (bool) $config['pretty']),
        ];
    }

    /**
     * Write a full dump into `<outBase>/hiyori-relational-<ts>/`.
     *
     * @param array<string, mixed> $config
     *
     * @return array{dir: string, written: array<string, int>, files: int, rows: int, changelog: array{added: int, updated: int, removed: int}, changed: int}
     */
    public function write(array $config): array
    {
        $config = ExportConfig::resolve($config);

        return $this->emit($this->fetchEntities($config), $config, null, $this->previousManifest());
    }

    // ---------------------------------------------------------------------
    // Core (DB-less) emitter
    // ---------------------------------------------------------------------

    /**
     * @param array{anime?: list<array<string, mixed>>, manga?: list<array<string, mixed>>} $entities
     * @param array<string, mixed>                                                          $config
     * @param array<string, mixed>|null                                                      $previousManifest
     *
     * @return array{dir: string, written: array<string, int>, files: int, rows: int, changelog: array{added: int, updated: int, removed: int}, changed: int}
     */
    public function emit(array $entities, array $config, ?string $dir = null, ?array $previousManifest = null): array
    {
        $config = ExportConfig::resolve($config);

        $missingIds = self::documentsMissingId($entities, $config);
        if ($missingIds !== []) {
            throw new \RuntimeException(sprintf(
                'refusing to export: %d document(s) have no hiyori_id (e.g. %s). Run `php hiyori hiyori-id --backfill` first. Without ids every entry is published as hiyori_id 0, filenames fall back to row position and the lookup table is keyed by that position.',
                count($missingIds),
                implode(', ', array_slice($missingIds, 0, 5)),
            ));
        }

        $dir ??= $this->outBase . '/hiyori-relational-' . (new \DateTime('now'))->format('Ymd-His');
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create export directory: ' . $dir);
        }

        /** @var array<string, array{sha256: string, bytes: int, rows: int}> $files */
        $files = [];
        /** @var array<string, int> $written */
        $written = [];
        $changed = 0;

        $subdir = (string) $config['subdir'];
        // Defence in depth: `subdir`, `layout` and `filename` all flow into the
        // write paths below, and `ExportConfig::resolve()` only trims slashes.
        // The API rejects traversal with a 400, but the CLI can pass a raw
        // `--config subdir=../../tmp`, so refuse to write outside the dump dir.
        if (str_contains($subdir, '..') || str_contains($subdir, '\\') || str_starts_with($subdir, '/')) {
            throw new \RuntimeException('refusing to write outside the export directory: subdir=' . $subdir);
        }

        $put = function (string $rel, string $content, int $rowCount) use (&$files, &$written, &$changed, $dir): void {
            if (str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '/')) {
                throw new \RuntimeException('refusing to write outside the export directory: ' . $rel);
            }
            if (self::writeIfChanged($dir . '/' . $rel, $content)) {
                ++$changed;
            }
            $files[$rel] = [
                'sha256' => hash('sha256', $content),
                'bytes' => strlen($content),
                'rows' => $rowCount,
            ];
            $written[$rel] = str_ends_with($rel, '.csv') ? $rowCount : strlen($content);
        };

        $tables = self::tableRows($entities);
        $counts = ['anime' => 0, 'manga' => 0];
        $lookup = [];

        foreach (['anime', 'manga'] as $kind) {
            if (!($config['entities'][$kind] ?? false)) {
                continue;
            }
            $items = self::sortDocs($entities[$kind] ?? []);
            $counts[$kind] = count($items);

            $used = [];
            $bulkLines = [];
            $writeEntries = (bool) $config['write_entries'] && str_ends_with((string) $config['layout'], '.json');

            foreach ($items as $item) {
                $doc = $item['doc'];
                $id = $item['id'];
                $title = is_string($doc['title'] ?? null) ? $doc['title'] : self::scalar($doc['title'] ?? '');
                $filename = self::uniqueFilename(self::renderFilename((string) $config['filename'], $id, $title), $used);
                $used[$filename] = true;

                $rel = self::renderLayout((string) $config['layout'], $subdir, $kind, $filename, $id);
                $entry = self::renderEntry($doc, $kind, $config, self::schemaPointerFor($rel));
                $bulkLines[] = self::jsonEncode($entry, false);

                if ($writeEntries) {
                    $put($rel, self::jsonEncode($entry, true) . "\n", 1);
                }

                $lookup[(string) $id] = [
                    'slug' => self::slugify($title),
                    'kind' => $kind,
                    'refs' => self::referenceIds($doc),
                ];
            }

            // Bulk JSONL mirror — always emitted per publish.
            $bulkRel = $subdir . '/' . $kind . '.jsonl';
            $put($bulkRel, $bulkLines === [] ? '' : implode("\n", $bulkLines) . "\n", count($bulkLines));
        }

        // Relational CSV tables (reused by the legacy `tables-csv` profile).
        foreach (['anime', 'manga'] as $kind) {
            if (!($config['entities'][$kind] ?? false) || !isset($tables[$kind])) {
                continue;
            }
            $put(
                $subdir . '/tables/' . $kind . '.csv',
                self::csvContent($tables[$kind]['header'], $tables[$kind]['rows']),
                count($tables[$kind]['rows']),
            );
        }
        $put($subdir . '/tables/entity_titles.csv', self::csvContent($tables['entity_titles']['header'], $tables['entity_titles']['rows']), count($tables['entity_titles']['rows']));
        $put($subdir . '/tables/entity_references.csv', self::csvContent($tables['entity_references']['header'], $tables['entity_references']['rows']), count($tables['entity_references']['rows']));

        $put('schema.sql', self::schemaSql(), 0);
        $put('README.md', self::readme($counts, (string) $config['profile']), 0);

        if ($config['index'] ?? true) {
            ksort($lookup, SORT_NUMERIC);
            $put('index/lookup.json', self::jsonEncode($lookup, true) . "\n", count($lookup));
        }

        // Schema authored by the schema teammate — copy into the dump if present.
        $schemaSrc = ExportConfig::rootDir() . '/' . self::SCHEMA_RELATIVE;
        if (is_file($schemaSrc)) {
            $schema = file_get_contents($schemaSrc);
            if ($schema !== false) {
                $put(self::SCHEMA_RELATIVE, $schema, 0);
            }
        }

        // manifest.json is built from everything above and excluded from itself.
        $generatedAt = (new \DateTime('now'))->format('c');
        $manifest = self::buildManifest($files, $counts, $generatedAt, (string) $config['profile']);
        $manifestJson = self::jsonEncode($manifest, true) . "\n";
        if (self::writeIfChanged($dir . '/manifest.json', $manifestJson)) {
            ++$changed;
        }
        $written['manifest.json'] = strlen($manifestJson);

        $diff = self::diffManifests(
            is_array($previousManifest['files'] ?? null) ? $previousManifest['files'] : null,
            $files,
        );
        $put('CHANGELOG.md', self::changelogMarkdown($diff, $generatedAt), 0);

        if ($config['bundle'] ?? true) {
            $tar = $dir . '.tar.gz';
            @exec(sprintf('tar -czf %s -C %s .', escapeshellarg($tar), escapeshellarg($dir)), $o, $rc);
            if (is_file($tar)) {
                $written['tar.gz'] = (int) filesize($tar);
            }
        }

        $rows = 0;
        foreach ($files as $meta) {
            $rows += $meta['rows'];
        }

        return [
            'dir' => $dir,
            'written' => $written,
            'files' => count($files),
            'rows' => $rows,
            'changelog' => ['added' => $diff['added'], 'updated' => $diff['updated'], 'removed' => $diff['removed']],
            'changed' => $changed,
        ];
    }

    // ---------------------------------------------------------------------
    // Pure rendering helpers
    // ---------------------------------------------------------------------

    /**
     * Render one organized document into its stable export entry.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function renderEntry(array $doc, string $kind, array $config, ?string $schemaPointer = null): array
    {
        $config = ExportConfig::resolve($config);
        $include = $config['include'];
        $toggles = static fn (string $key): bool => (bool) ($include[$key] ?? false);

        $title = is_string($doc['title'] ?? null) ? $doc['title'] : self::scalar($doc['title'] ?? '');
        $id = self::resolveId($doc, 0);
        $synonymsRaw = $doc['synonyms'] ?? [];
        $synonyms = self::synonymValues($synonymsRaw);

        $entry = [];
        $entry['$schema'] = $schemaPointer ?? self::DEFAULT_SCHEMA_POINTER;
        $entry['hiyori_id'] = $id;
        $entry['slug'] = self::slugify($title);
        $entry['kind'] = $kind;
        $entry['title'] = $title;

        if ($toggles('titles')) {
            $entry['titles'] = self::titleBlock($title, $synonymsRaw);
        }
        if ($toggles('synonyms')) {
            $entry['synonyms'] = $synonyms;
        }

        $scalars = self::scalarFields($doc, $kind);
        foreach (['type', 'episodes', 'chapters', 'volumes', 'status', 'season', 'year'] as $field) {
            if (array_key_exists($field, $scalars)) {
                $entry[$field] = $scalars[$field];
            }
        }

        if ($toggles('tags') && !empty($doc['tags'])) {
            $entry['tags'] = self::stringList($doc['tags']);
        }
        if ($toggles('genres') && !empty($doc['genres'])) {
            $entry['genres'] = self::stringList($doc['genres']);
        }
        if ($toggles('synopsis') && is_string($doc['synopsis'] ?? null) && $doc['synopsis'] !== '') {
            $entry['synopsis'] = $doc['synopsis'];
        }
        if ($toggles('score') && isset($doc['score']) && is_numeric($doc['score'])) {
            $entry['score'] = $doc['score'] + 0;
        }
        if ($toggles('studios') && !empty($doc['studios'])) {
            $entry['studios'] = self::stringList($doc['studios']);
        }

        $images = self::stringList($doc['images'] ?? []);
        if ($images !== []) {
            $entry['images'] = $images;
        }

        if ($toggles('references')) {
            $references = self::referenceIds($doc);
            $urls = $toggles('links') ? self::links($doc) : [];
            $merged = [];
            foreach ($references as $source => $sourceId) {
                $item = ['id' => $sourceId];
                if (isset($urls[$source])) {
                    $item['url'] = $urls[$source];
                }
                $merged[$source] = $item;
            }
            ksort($merged);
            $entry['references'] = $merged === [] ? new \stdClass() : $merged;
        }

        $fields = $config['fields'];
        if ($fields !== []) {
            $always = ['$schema', 'hiyori_id', 'slug', 'kind'];
            $keep = array_flip(array_unique(array_merge($always, $fields))) + array_flip($always);
            $entry = array_intersect_key($entry, $keep);
        }

        return $entry;
    }

    /**
     * Resolved site URLs keyed by reference shorthand.
     *
     * @param array<string, mixed> $doc
     *
     * @return array<string, string>
     */
    public static function links(array $doc): array
    {
        $links = [];
        foreach (self::values($doc['references'] ?? []) as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $parsed = Identifiers::parseId($url);
            $identifierClass = $parsed[0] ?? null;
            $identifierId = $parsed[1] ?? null;
            if (!is_string($identifierClass) || !is_string($identifierId) || !class_exists($identifierClass)) {
                continue;
            }
            $shorthand = constant($identifierClass . '::SHORTHAND');
            $template = constant($identifierClass . '::ENTRY_URL');
            if (!is_string($shorthand) || !is_string($template) || isset($links[$shorthand])) {
                continue;
            }
            $links[$shorthand] = sprintf($template, $identifierId);
        }

        foreach (self::referenceIds($doc) as $shorthand => $id) {
            if (isset($links[$shorthand]) || !isset(self::LINK_TEMPLATES[$shorthand])) {
                continue;
            }
            $links[$shorthand] = sprintf(self::LINK_TEMPLATES[$shorthand], $id);
        }

        ksort($links);

        return $links;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, string>
     */
    public static function referenceIds(array $doc): array
    {
        $out = [];
        foreach (($doc['reference_ids'] ?? []) as $shorthand => $id) {
            if (!is_string($shorthand) || $shorthand === '') {
                continue;
            }
            $out[$shorthand] = self::scalar($id);
        }
        ksort($out);

        return $out;
    }

    public static function renderFilename(string $pattern, int $id, string $title): string
    {
        return strtr($pattern, [
            '{hiyori_id}' => (string) $id,
            '{id}' => (string) $id,
            '{slug}' => self::slugify($title),
        ]);
    }

    public static function renderLayout(string $layout, string $subdir, string $kind, string $filename, int $id): string
    {
        return strtr($layout, [
            '{subdir}' => $subdir,
            '{kind}' => $kind,
            '{filename}' => $filename,
            '{hiyori_id}' => (string) $id,
            '{id}' => (string) $id,
        ]);
    }

    /**
     * Append a `-2`/`-3` suffix when a filename was already used this run.
     *
     * @param array<string, bool> $used
     */
    public static function uniqueFilename(string $candidate, array $used): string
    {
        if (!isset($used[$candidate])) {
            return $candidate;
        }
        $n = 2;
        while (isset($used[$candidate . '-' . $n])) {
            ++$n;
        }

        return $candidate . '-' . $n;
    }

    /**
     * Deterministic 2-space pretty printer (JSON_PRETTY_PRINT uses 4 spaces).
     */
    public static function jsonEncode(mixed $value, bool $pretty, int $level = 0): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $encoded = json_encode($value);

            return $encoded === false ? 'null' : $encoded;
        }
        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded === false ? '""' : $encoded;
        }
        if ($value instanceof \stdClass) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded === false ? '{}' : $encoded;
        }
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $isList = array_is_list($value);
            $open = $isList ? '[' : '{';
            $close = $isList ? ']' : '}';
            $inner = str_repeat('  ', $level + 1);
            $outer = str_repeat('  ', $level);
            $parts = [];
            foreach ($value as $key => $item) {
                $encoded = self::jsonEncode($item, $pretty, $level + 1);
                if ($isList) {
                    $parts[] = ($pretty ? $inner : '') . $encoded;

                    continue;
                }
                $encodedKey = self::jsonEncode((string) $key, false);
                $parts[] = ($pretty ? $inner : '') . $encodedKey . ($pretty ? ': ' : ':') . $encoded;
            }
            $separator = $pretty ? ",\n" : ',';

            return $open . ($pretty ? "\n" : '') . implode($separator, $parts) . ($pretty ? "\n" . $outer : '') . $close;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? 'null' : $encoded;
    }

    public static function entryJson(array $entry, bool $pretty): string
    {
        return self::jsonEncode($entry, $pretty);
    }

    /**
     * @param array<string, array<string, mixed>> $files
     * @param array<string, mixed>                $counts
     *
     * @return array<string, mixed>
     */
    public static function buildManifest(array $files, array $counts, string $generatedAt, string $profile): array
    {
        $out = [];
        foreach ($files as $name => $meta) {
            $out[$name] = [
                'sha256' => (string) ($meta['sha256'] ?? ''),
                'bytes' => (int) ($meta['bytes'] ?? 0),
                'rows' => (int) ($meta['rows'] ?? 0),
            ];
        }
        ksort($out);

        $rows = 0;
        foreach ($out as $meta) {
            $rows += $meta['rows'];
        }

        return [
            'profile' => $profile,
            'generated_at' => $generatedAt,
            'counts' => [
                'anime' => (int) ($counts['anime'] ?? 0),
                'manga' => (int) ($counts['manga'] ?? 0),
                'files' => count($out),
                'rows' => $rows,
            ],
            'content_hash' => self::contentHash($out),
            'files' => $out,
        ];
    }

    /**
     * Top-level content hash: sha256 over sorted `name\0sha256` pairs.
     *
     * @param array<string, array<string, mixed>> $files
     */
    public static function contentHash(array $files): string
    {
        ksort($files);
        $buffer = '';
        foreach ($files as $name => $meta) {
            $buffer .= $name . "\0" . (string) ($meta['sha256'] ?? '') . "\n";
        }

        return 'sha256:' . hash('sha256', $buffer);
    }

    /**
     * Compare two manifest `files` maps by checksum.
     *
     * @param array<string, mixed>|null $previousFiles
     * @param array<string, mixed>      $currentFiles
     *
     * @return array{added: int, updated: int, removed: int, files: array{added: list<string>, updated: list<string>, removed: list<string>}}
     */
    public static function diffManifests(?array $previousFiles, array $currentFiles): array
    {
        $previousFiles ??= [];
        $added = [];
        $updated = [];

        foreach ($currentFiles as $name => $meta) {
            $current = is_array($meta) ? (string) ($meta['sha256'] ?? '') : '';
            if (!array_key_exists($name, $previousFiles)) {
                $added[] = $name;

                continue;
            }
            $previousMeta = $previousFiles[$name];
            $previous = is_array($previousMeta) ? (string) ($previousMeta['sha256'] ?? '') : '';
            if ($previous !== $current) {
                $updated[] = $name;
            }
        }

        $removed = [];
        foreach (array_keys($previousFiles) as $name) {
            if (!array_key_exists($name, $currentFiles)) {
                $removed[] = (string) $name;
            }
        }

        sort($added);
        sort($updated);
        sort($removed);

        return [
            'added' => count($added),
            'updated' => count($updated),
            'removed' => count($removed),
            'files' => ['added' => $added, 'updated' => $updated, 'removed' => $removed],
        ];
    }

    /**
     * Verify a written dump against its manifest: every listed file must
     * exist with the recorded sha256 and size, and the root content hash must
     * match. Used by `publish --verify` and the release pipeline.
     *
     * @return array{ok: bool, checked: int, content_hash: ?string, issues: list<string>, missing: list<string>, mismatched: list<string>}
     */
    public static function verify(string $dir): array
    {
        $manifestPath = rtrim($dir, '/') . '/manifest.json';
        if (!is_file($manifestPath)) {
            return [
                'ok' => false,
                'checked' => 0,
                'content_hash' => null,
                'issues' => ['manifest.json is missing'],
                'missing' => [],
                'mismatched' => [],
            ];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return [
                'ok' => false,
                'checked' => 0,
                'content_hash' => null,
                'issues' => ['manifest.json is not valid JSON'],
                'missing' => [],
                'mismatched' => [],
            ];
        }

        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $missing = [];
        $mismatched = [];
        $issues = [];

        foreach ($files as $rel => $meta) {
            $path = rtrim($dir, '/') . '/' . $rel;
            if (!is_file($path)) {
                $missing[] = (string) $rel;

                continue;
            }
            $expectedHash = is_array($meta) ? (string) ($meta['sha256'] ?? '') : '';
            $expectedBytes = is_array($meta) ? (int) ($meta['bytes'] ?? -1) : -1;
            if ($expectedHash !== '' && hash_file('sha256', $path) !== $expectedHash) {
                $mismatched[] = (string) $rel;

                continue;
            }
            if ($expectedBytes >= 0 && filesize($path) !== $expectedBytes) {
                $mismatched[] = (string) $rel;
            }
        }

        if ($missing !== []) {
            $issues[] = count($missing) . ' manifest file(s) missing';
        }
        if ($mismatched !== []) {
            $issues[] = count($mismatched) . ' file(s) failed checksum/size validation';
        }

        $contentHash = self::contentHash($files);
        if (($manifest['content_hash'] ?? null) !== $contentHash) {
            $issues[] = 'content_hash does not match manifest file table';
        }

        return [
            'ok' => $issues === [],
            'checked' => count($files),
            'content_hash' => $contentHash,
            'issues' => $issues,
            'missing' => $missing,
            'mismatched' => $mismatched,
        ];
    }

    /**
     * Write only when the content differs, so git diffs stay small.
     */
    public static function writeIfChanged(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }
        if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $content)) {
            return false;
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write export file: ' . $path);
        }

        return true;
    }

    /**
     * @param array{anime?: list<array<string, mixed>>, manga?: list<array<string, mixed>>} $entities
     * @param array<string, mixed>                                                          $config
     *
     * @return array{files: int, byKind: array{anime: int, manga: int}, sampleFilenames: list<string>, bytesEstimate: int}
     */
    public static function estimate(array $entities, array $config): array
    {
        $config = ExportConfig::resolve($config);
        $byKind = ['anime' => 0, 'manga' => 0];
        $samples = [];
        $bytes = 0;
        $files = 0;
        $writeEntries = (bool) $config['write_entries'];

        foreach (['anime', 'manga'] as $kind) {
            if (!($config['entities'][$kind] ?? false)) {
                continue;
            }
            $items = self::sortDocs($entities[$kind] ?? []);
            $byKind[$kind] = count($items);
            if ($writeEntries) {
                $files += count($items);
            } elseif ($items !== []) {
                ++$files;
            }

            $used = [];
            foreach ($items as $item) {
                $entry = self::renderEntry($item['doc'], $kind, $config);
                $bytes += strlen(self::jsonEncode($entry, (bool) $config['pretty']));

                $title = is_string($item['doc']['title'] ?? null) ? $item['doc']['title'] : '';
                $filename = self::uniqueFilename(self::renderFilename((string) $config['filename'], $item['id'], $title), $used);
                $used[$filename] = true;
                if (count($samples) < 10) {
                    $samples[] = $filename;
                }
            }
        }

        return ['files' => $files, 'byKind' => $byKind, 'sampleFilenames' => $samples, 'bytesEstimate' => $bytes];
    }

    // ---------------------------------------------------------------------
    // CSV tables
    // ---------------------------------------------------------------------

    /**
     * @param array{anime?: list<array<string, mixed>>, manga?: list<array<string, mixed>>} $entities
     *
     * @return array<string, array{header: list<string>, rows: list<list<mixed>>}>
     */
    public static function tableRows(array $entities): array
    {
        $tables = [
            'anime' => ['header' => ['id', 'slug', 'title', 'type', 'count', 'status', 'season', 'year', 'image'], 'rows' => []],
            'manga' => ['header' => ['id', 'slug', 'title', 'type', 'count', 'volumes', 'status', 'year', 'image'], 'rows' => []],
            'entity_titles' => ['header' => ['entity_type', 'entity_id', 'lang', 'value'], 'rows' => []],
            'entity_references' => ['header' => ['entity_type', 'entity_id', 'source', 'source_id'], 'rows' => []],
        ];

        foreach (['anime', 'manga'] as $kind) {
            foreach ($entities[$kind] ?? [] as $doc) {
                $title = is_string($doc['title'] ?? null) ? $doc['title'] : self::scalar($doc['title'] ?? '');
                $id = self::resolveId($doc, 0);
                $count = $kind === 'anime' ? (int) ($doc['episodes'] ?? 0) : (int) ($doc['chapters'] ?? 0);
                $extra = $kind === 'anime' ? (string) ($doc['season'] ?? '') : (string) ((int) ($doc['volumes'] ?? 0));
                $tables[$kind]['rows'][] = [
                    (string) $id,
                    self::slugify($title),
                    $title,
                    self::scalar($doc['type'] ?? ''),
                    $count,
                    self::scalar($doc['status'] ?? ''),
                    $extra,
                    is_int($doc['year'] ?? null) || is_string($doc['year'] ?? null) ? $doc['year'] : null,
                    self::scalar($doc['images'] ?? ''),
                ];

                foreach (self::titleRows($id, $kind, $title, $doc['synonyms'] ?? []) as $row) {
                    $tables['entity_titles']['rows'][] = $row;
                }
                foreach (self::referenceIds($doc) as $source => $sourceId) {
                    $tables['entity_references']['rows'][] = [$kind, (string) $id, $source, $sourceId];
                }
            }
        }

        return $tables;
    }

    /**
     * @param list<string> $header
     * @param list<list<mixed>> $rows
     */
    public static function csvContent(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open a temporary stream for CSV rendering.');
        }
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content === false ? '' : $content;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     *
     * @return array{anime: list<array<string, mixed>>, manga: list<array<string, mixed>>}
     */
    private function fetchEntities(array $config): array
    {
        $entities = ['anime' => [], 'manga' => []];
        if ($this->client === null) {
            return $entities;
        }

        $db = $this->client->selectDatabase($this->database);
        $collections = ['anime' => 'organized', 'manga' => 'organized_manga'];
        foreach ($collections as $kind => $collection) {
            if (!($config['entities'][$kind] ?? false)) {
                continue;
            }
            foreach ($db->selectCollection($collection)->find() as $doc) {
                $decoded = json_decode(json_encode($doc), true);
                if (is_array($decoded)) {
                    $entities[$kind][] = $decoded;
                }
            }
        }

        return $entities;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previousManifest(): ?array
    {
        $dirs = glob($this->outBase . '/hiyori-relational-*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return null;
        }
        sort($dirs);
        for ($i = count($dirs) - 1; $i >= 0; --$i) {
            $path = $dirs[$i] . '/manifest.json';
            if (!is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && is_array($decoded['files'] ?? null)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $docs
     *
     * @return list<array{id: int, doc: array<string, mixed>}>
     */
    /**
     * Documents in the enabled kinds that carry no positive `hiyori_id`.
     *
     * `renderEntry()` fell back to 0 and `sortDocs()` to the row position, so a
     * dump taken before `hiyori-id --backfill` published N entries all claiming
     * hiyori_id 0 while the files and the lookup disagreed with them.
     *
     * @param array<string, mixed> $entities
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function documentsMissingId(array $entities, array $config): array
    {
        $missing = [];
        foreach (['anime', 'manga'] as $kind) {
            if (!($config['entities'][$kind] ?? false)) {
                continue;
            }
            foreach (($entities[$kind] ?? []) as $index => $doc) {
                $id = $doc['hiyori_id'] ?? null;
                $usable = (is_int($id) && $id > 0) || (is_numeric($id) && (int) $id > 0);
                if ($usable) {
                    continue;
                }
                $ref = $doc['_id'] ?? null;
                $missing[] = $kind . '#' . (is_scalar($ref) ? (string) $ref : (string) $index);
            }
        }

        return $missing;
    }

    private static function sortDocs(array $docs): array
    {
        $items = [];
        $position = 0;
        foreach ($docs as $doc) {
            ++$position;
            $items[] = ['id' => self::resolveId($doc, $position), 'doc' => $doc];
        }
        usort($items, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $items;
    }

    /**
     * Canonical schema pointer relative to a rendered file path.
     */
    private static function schemaPointerFor(string $relPath): string
    {
        $depth = substr_count($relPath, '/');

        return $depth > 0 ? str_repeat('../', $depth) . self::SCHEMA_RELATIVE : self::SCHEMA_RELATIVE;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function scalarFields(array $doc, string $kind): array
    {
        $allowed = $kind === 'manga'
            ? ['type', 'chapters', 'volumes', 'status', 'year']
            : ['type', 'episodes', 'status', 'season', 'year'];

        $out = [];
        foreach ($allowed as $field) {
            $value = $doc[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * @param mixed $synonyms
     *
     * @return array<string, string>
     */
    private static function titleBlock(string $title, mixed $synonyms): array
    {
        $titles = ['default' => $title];
        if (is_array($synonyms)) {
            foreach ($synonyms as $lang => $value) {
                if (is_string($lang) && !is_numeric($lang) && is_string($value) && trim($value) !== '') {
                    $titles[$lang] = trim($value);
                }
            }
        }

        return $titles;
    }

    /**
     * @param mixed $synonyms
     *
     * @return list<string>
     */
    private static function synonymValues(mixed $synonyms): array
    {
        $out = [];
        foreach (self::values($synonyms) as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $out[trim($value)] = trim($value);
        }

        return array_values($out);
    }

    /**
     * @param mixed $value
     *
     * @return list<mixed>
     */
    private static function values(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $out = [];
        foreach (self::values($value) as $item) {
            if (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                $item = $item['name'];
            }
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $out[trim($item)] = trim($item);
        }

        return array_values($out);
    }

    /**
     * @param int $id
     * @param array<string, mixed> $synonyms
     *
     * @return list<list<mixed>>
     */
    private static function titleRows(int $id, string $kind, string $title, mixed $synonyms): array
    {
        $rows = [];
        if ($title !== '') {
            $rows[] = [$kind, (string) $id, 'main', $title];
        }
        foreach (self::titleBlock($title, $synonyms) as $lang => $value) {
            if ($lang === 'default') {
                continue;
            }
            $rows[] = [$kind, (string) $id, $lang, $value];
        }
        foreach (self::synonymValues($synonyms) as $value) {
            if ($value === $title) {
                continue;
            }
            $rows[] = [$kind, (string) $id, 'synonym', $value];
        }

        return $rows;
    }

    private static function resolveId(array $doc, int $fallback): int
    {
        $id = $doc['hiyori_id'] ?? null;
        if (is_int($id)) {
            return $id;
        }
        if (is_numeric($id)) {
            return (int) $id;
        }

        return $fallback;
    }

    public static function slugify(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));
        $slug = rtrim(substr($slug, 0, 120), '-');
        if ($slug === '') {
            $slug = 'untitled';
        }

        return $slug;
    }

    /** Flatten a Mongo field to a single-line string (arrays -> first scalar / JSON). */
    public static function scalar(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_array($item)) {
                    return (string) $item;
                }
            }

            return (string) json_encode($value);
        }

        return (string) ($value ?? '');
    }

    private static function schemaSql(): string
    {
        return <<<SQL
            -- HiyoriDB relational schema (generated by `php hiyori export`)
            CREATE TABLE anime (
              id INTEGER PRIMARY KEY, slug TEXT, title TEXT, type TEXT, episodes INTEGER,
              status TEXT, season TEXT, year INTEGER, image TEXT
            );
            CREATE TABLE manga (
              id INTEGER PRIMARY KEY, slug TEXT, title TEXT, type TEXT, chapters INTEGER,
              volumes INTEGER, status TEXT, year INTEGER, image TEXT
            );
            CREATE TABLE entity_titles (
              entity_type TEXT, entity_id INTEGER, lang TEXT, value TEXT,
              PRIMARY KEY (entity_type, entity_id, lang, value)
            );
            CREATE TABLE entity_references (
              entity_type TEXT, entity_id INTEGER, source TEXT, source_id TEXT,
              PRIMARY KEY (entity_type, entity_id, source, source_id)
            );
            SQL;
    }

    /**
     * @param array{anime: int, manga: int} $counts
     */
    private static function readme(array $counts, string $profile): string
    {
        return "# HiyoriDB\n\nRelational dataset produced by the Hiyori CLI pipeline.\n\n"
            . "- Profile: {$profile}\n"
            . "- Anime records: {$counts['anime']}\n"
            . "- Manga records: {$counts['manga']}\n\n"
            . "Tables: `data/tables/{anime,manga,entity_titles,entity_references}.csv` (see `schema.sql`).\n"
            . "Per-entry JSON: `data/<kind>/<hiyori_id>-<slug>.json`; bulk mirror: `data/<kind>.jsonl`.\n";
    }

    /**
     * @param array{added: int, updated: int, removed: int, files: array{added: list<string>, updated: list<string>, removed: list<string>}} $diff
     */
    private static function changelogMarkdown(array $diff, string $generatedAt): string
    {
        $lines = [
            '# Changelog',
            '',
            'Generated: ' . $generatedAt,
            '',
            '## Summary',
            '',
            '- Added: ' . $diff['added'],
            '- Updated: ' . $diff['updated'],
            '- Removed: ' . $diff['removed'],
            '',
        ];

        foreach (['added' => 'Added', 'updated' => 'Updated', 'removed' => 'Removed'] as $key => $label) {
            if ($diff['files'][$key] === []) {
                continue;
            }
            $lines[] = '## ' . $label;
            $lines[] = '';
            foreach ($diff['files'][$key] as $name) {
                $lines[] = '- `' . $name . '`';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
