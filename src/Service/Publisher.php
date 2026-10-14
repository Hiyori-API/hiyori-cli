<?php

namespace Hiyori\Service;

use MongoDB\Client;

/**
 * Relational dump + publish pipeline for HiyoriDB (back-compat facade).
 *
 * Delegates to {@see Exporter} using the effective {@see ExportConfig}
 * profile. The default profile is `per-entry-json`; the legacy normalized
 * CSV dataset is available via the `tables-csv` profile.
 */
final class Publisher
{
    private const PUBLISH_DIR = '/storage/publish';

    private string $outBase;

    public function __construct(
        private Client $client,
        private string $database = 'hiyori',
        ?string $outBase = null
    ) {
        $this->outBase = $outBase ?? dirname(__DIR__, 2) . self::PUBLISH_DIR;
    }

    /**
     * @param array<string, mixed>|null $config export config overrides
     *
     * @return array{written: array<string, int>, rows: int, dir: string}
     */
    public function dump(bool $gzip = true, bool $includeManga = true, ?array $config = null): array
    {
        $config ??= ExportConfig::load();
        $config = ExportConfig::merge($config, [
            'entities' => ['manga' => $includeManga && (bool) ($config['entities']['manga'] ?? true)],
            'bundle' => $gzip,
        ]);

        $result = (new Exporter($this->client, $this->database, $this->outBase))->write($config);

        return [
            'written' => $result['written'],
            'rows' => $result['rows'],
            'dir' => $result['dir'],
        ];
    }

    public function slug(string $title): string
    {
        return Exporter::slugify($title);
    }

    public function scalar(mixed $v): string
    {
        return Exporter::scalar($v);
    }
}
