<?php

namespace Hiyori\Service;

use MongoDB\Client;
use MongoDB\Collection;

/**
 * Relational dump + publish pipeline for HiyoriDB.
 *
 * Dump: streams `organized` / `organized_manga` into a normalized relational
 * dataset (entity + titles + references CSVs + schema.sql), then optionally
 * pushes it into the HiyoriDB repository.
 */
final class Publisher
{
    private const PUBLISH_DIR = '/storage/publish';

    public function __construct(
        private Client $client,
        private string $database = 'hiyori',
        ?string $outBase = null
    ) {
        $this->outBase = $outBase ?? dirname(__DIR__, 2) . self::PUBLISH_DIR;
    }

    private string $outBase;

    /** @return array{written: array<string,int>, rows: int} */
    public function dump(bool $gzip = true, bool $includeManga = true): array
    {
        $stamp = (new \DateTime('now'))->format('Ymd-His');
        $dir = $this->outBase . '/hiyori-relational-' . $stamp;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $db = $this->client->selectDatabase($this->database);
        $anime = $db->selectCollection('organized');
        $manga = $includeManga ? $db->selectCollection('organized_manga') : null;

        $written = [];
        $rows = 0;

        // entity tables
        $rows += $this->writeEntityTable($dir . '/anime.csv', $anime, 'anime', $written);
        if ($manga !== null) {
            $rows += $this->writeEntityTable($dir . '/manga.csv', $manga, 'manga', $written);
        }
        // titles + references (both collections -> one set each)
        $rows += $this->writeTitles($dir . '/entity_titles.csv', $anime, 'anime', $manga, $written);
        $rows += $this->writeReferences($dir . '/entity_references.csv', $anime, 'anime', $manga, $written);

        // schema.sql (DDL mirroring the CSVs)
        $schema = $this->schema();
        file_put_contents($dir . '/schema.sql', $schema);
        $written['schema.sql'] = strlen($schema);

        // README with stats
        $readme = $this->readme($rows, (int) $anime->countDocuments(), $manga !== null ? (int) $manga->countDocuments() : 0);
        file_put_contents($dir . '/README.md', $readme);
        $written['README.md'] = strlen($readme);

        if ($gzip) {
            $tar = $this->outBase . '/hiyori-relational-' . $stamp . '.tar.gz';
            $cmd = sprintf('tar -czf %s -C %s .', escapeshellarg($tar), escapeshellarg($dir));
            @exec($cmd, $o, $rc);
            $written['tar.gz'] = is_file($tar) ? (int) filesize($tar) : 0;
        }

        return ['written' => $written, 'rows' => $rows, 'dir' => $dir];
    }

    /** Stream an entity collection into a CSV with flattened scalar fields. */
    private function writeEntityTable(string $path, Collection $coll, string $kind, array &$written): int
    {
        $fp = fopen($path, 'w');
        $header = ['id', 'slug', 'title', 'type', 'count', 'status', 'season', 'year', 'image'];
        fputcsv($fp, $header);
        $rows = 0;
        foreach ($coll->find() as $doc) {
            $d = json_decode(json_encode($doc), true);
            $title = self::scalar($d['title'] ?? '');
            $count = $kind === 'anime' ? (int) ($d['episodes'] ?? 0) : (int) ($d['chapters'] ?? 0);
            $second = $kind === 'anime' ? '' : (int) ($d['volumes'] ?? 0);
            $season = $kind === 'anime' ? (string) ($d['season'] ?? '') : '';
            $year = $d['year'] ?? null;
            $img = self::scalar(($d['images'] ?? '') === [] || is_array($d['images'] ?? null) ? ($d['images'][0] ?? '') : ($d['images'] ?? ''));
            fputcsv($fp, [
                self::scalar($d['_id'] ?? ''),
                $this->slug($title),
                $title,
                self::scalar($d['type'] ?? ''),
                $count, $second,
                self::scalar($d['status'] ?? ''),
                $season,
                is_int($year) || is_string($year) ? $year : null,
                $img,
            ]);
            $rows++;
        }
        fclose($fp);
        $written[basename($path)] = $rows;

        return $rows;
    }

    private function writeTitles(string $path, Collection $anime, string $kindA, ?Collection $manga, array &$written): int
    {
        $fp = fopen($path, 'w');
        fputcsv($fp, ['entity_type', 'entity_id', 'lang', 'value']);
        $rows = 0;
        foreach ([[$anime, $kindA], $manga ? [$manga, 'manga'] : null] as $pair) {
            if ($pair === null) {
                continue;
            }
            [$coll, $kind] = $pair;
            foreach ($coll->find() as $doc) {
                $d = json_decode(json_encode($doc), true);
                $id = self::scalar($d['_id'] ?? '');
                $title = $d['title'] ?? '';
                $syn = $d['synonyms'] ?? [];
                if (is_string($title)) {
                    fputcsv($fp, [$kind, $id, 'main', $title]);
                    $rows++;
                } elseif (is_array($title)) {
                    foreach (['romaji', 'english', 'native'] as $lang) {
                        if (!empty($title[$lang])) {
                            fputcsv($fp, [$kind, $id, $lang, self::scalar($title[$lang])]);
                            $rows++;
                        }
                    }
                }
                if (is_array($syn)) {
                    $seen = [];
                    foreach ($syn as $lang => $value) {
                        if (!is_string($value) || $value === '' || isset($seen[$value])) {
                            continue;
                        }
                        $seen[$value] = true;
                        fputcsv($fp, [$kind, $id, is_string($lang) ? $lang : 'synonym', self::scalar($value)]);
                        $rows++;
                    }
                }
            }
        }
        fclose($fp);
        $written[basename($path)] = $rows;

        return $rows;
    }

    private function writeReferences(string $path, Collection $anime, string $kindA, ?Collection $manga, array &$written): int
    {
        $fp = fopen($path, 'w');
        fputcsv($fp, ['entity_type', 'entity_id', 'source', 'source_id']);
        $rows = 0;
        foreach ([[$anime, $kindA], $manga ? [$manga, 'manga'] : null] as $pair) {
            if ($pair === null) {
                continue;
            }
            [$coll, $kind] = $pair;
            foreach ($coll->find([], ['projection' => ['_id' => 1, 'reference_ids' => 1]]) as $doc) {
                $d = json_decode(json_encode($doc), true);
                $id = self::scalar($d['_id'] ?? '');
                foreach (($d['reference_ids'] ?? []) as $sh => $shId) {
                    fputcsv($fp, [$kind, $id, self::scalar($sh), self::scalar($shId)]);
                    $rows++;
                }
            }
        }
        fclose($fp);
        $written[basename($path)] = $rows;

        return $rows;
    }

    private function schema(): string
    {
        return <<<SQL
-- HiyoriDB relational schema (generated by `php hiyori publish`)
CREATE TABLE anime (
  id TEXT PRIMARY KEY, slug TEXT, title TEXT, type TEXT, episodes INTEGER,
  status TEXT, season TEXT, year INTEGER, image TEXT
);
CREATE TABLE manga (
  id TEXT PRIMARY KEY, slug TEXT, title TEXT, type TEXT, chapters INTEGER,
  volumes INTEGER, status TEXT, year INTEGER, image TEXT
);
CREATE TABLE entity_titles (
  entity_type TEXT, entity_id TEXT, lang TEXT, value TEXT,
  PRIMARY KEY (entity_type, entity_id, lang, value)
);
CREATE TABLE entity_references (
  entity_type TEXT, entity_id TEXT, source TEXT, source_id TEXT,
  PRIMARY KEY (entity_type, entity_id, source, source_id)
);
SQL;
    }

    private function readme(int $rows, int $animeCount, int $mangaCount): string
    {
        return "# HiyoriDB\n\nRelational dataset produced by the Hiyori CLI pipeline.\n\n"
            . "- Anime records: {$animeCount}\n"
            . "- Manga records: {$mangaCount}\n"
            . "- Relational rows (titles + references): {$rows}\n\n"
            . "Tables: `anime`, `manga`, `entity_titles`, `entity_references` (see `schema.sql`).\n"
            . "Generated: " . date('c') . "\n";
    }


    /** Flatten a Mongo field to a single-line string (arrays -> first scalar / JSON). */
    private static function scalar(mixed $v): string
    {
        if (is_array($v)) {
            foreach ($v as $x) {
                if (!is_array($x)) {
                    return (string) $x;
                }
            }

            return (string) json_encode($v);
        }

        return (string) ($v ?? '');
    }

    private function slug(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));
        if ($slug === '') {
            $slug = 'untitled';
        }

        return substr($slug, 0, 120);
    }
}
