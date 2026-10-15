<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Service\Database;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/**
 * Human-in-the-loop pair labels (`_labels`), the ground truth for training and
 * benchmarking the `metadata_ml` scorer.
 *
 * A label targets one candidate pair and is unique on
 * `(base_source, base_id, candidate_source, candidate_id)` — the same key the
 * v4 migration indexes — so repeated votes update rather than duplicate.
 * Labels are authoritative over the `organized` collection when exporting.
 */
final class LabelStore
{
    public const COLLECTION = '_labels';

    public function __construct(private Collection $collection)
    {
    }

    public static function create(Database $db, string $collection = self::COLLECTION): self
    {
        return new self($db->getCollection($collection));
    }

    /**
     * Required: `base_source`, `base_id`, `candidate_source`, `candidate_id`.
     * Optional: `label` (1 = agree, default 0), `source`, `notes`, `features`.
     *
     * @param array<string, mixed> $label
     */
    public function upsert(array $label): bool
    {
        $baseSource = trim((string) ($label['base_source'] ?? ''));
        $baseId = trim((string) ($label['base_id'] ?? ''));
        $candidateSource = trim((string) ($label['candidate_source'] ?? ''));
        $candidateId = trim((string) ($label['candidate_id'] ?? ''));
        if ($baseSource === '' || $baseId === '' || $candidateSource === '' || $candidateId === '') {
            return false;
        }

        $value = ((int) ($label['label'] ?? 0)) >= 1 ? 1 : 0;
        $set = [
            'subject' => 'pair',
            'base_source' => $baseSource,
            'base_id' => $baseId,
            'candidate_source' => $candidateSource,
            'candidate_id' => $candidateId,
            'label' => $value,
            'decision' => $value === 1 ? 'agree' : 'disagree',
            'source' => (string) ($label['source'] ?? 'human'),
            'notes' => (string) ($label['notes'] ?? ''),
            'updated_at' => new UTCDateTime(),
        ];
        if (isset($label['features']) && is_array($label['features'])) {
            $set['features'] = $label['features'];
        }

        $result = $this->collection->updateOne(
            [
                'base_source' => $baseSource,
                'base_id' => $baseId,
                'candidate_source' => $candidateSource,
                'candidate_id' => $candidateId,
            ],
            ['$set' => $set, '$setOnInsert' => ['created_at' => new UTCDateTime()]],
            ['upsert' => true],
        );

        return $result->isAcknowledged();
    }

    /**
     * All labels for one base entry, keyed by `candidate_source:candidate_id`.
     *
     * @return array<string, int>
     */
    public function forBase(string $baseSource, string $baseId): array
    {
        $out = [];
        $cursor = $this->collection->find(
            ['base_source' => $baseSource, 'base_id' => (string) $baseId],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        );
        foreach ($cursor as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $key = (string) ($doc['candidate_source'] ?? '') . ':' . (string) ($doc['candidate_id'] ?? '');
            $out[$key] = (int) ($doc['label'] ?? 0);
        }

        return $out;
    }

    public function count(?int $label = null): int
    {
        if ($label === null) {
            return $this->collection->countDocuments();
        }

        return $this->collection->countDocuments(['label' => $label >= 1 ? 1 : 0]);
    }

    /**
     * @return array{total: int, positives: int, negatives: int, by_source: array<string, int>}
     */
    public function stats(): array
    {
        $bySource = [];
        $cursor = $this->collection->aggregate([
            ['$group' => ['_id' => '$source', 'n' => ['$sum' => 1]]],
        ], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        foreach ($cursor as $row) {
            if (is_array($row)) {
                $bySource[(string) ($row['_id'] ?? 'unknown')] = (int) ($row['n'] ?? 0);
            }
        }

        $positives = $this->count(1);

        return [
            'total' => $this->count(),
            'positives' => $positives,
            'negatives' => $this->count(0),
            'by_source' => $bySource,
        ];
    }
}
