<?php

declare(strict_types=1);

namespace Hiyori\Service\Ml;

use Hiyori\Service\Database;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/**
 * Rejection buffer (`pair_rejections`) — the review queue for candidate pairs
 * that were turned down, with multiclass verdicts (approve / reject / unsure).
 *
 * The buffer is the human-in-the-loop bridge between "the scorer said no" and
 * "the trainer learns from it": a reviewer works the queue, and
 * {@see promote()} copies the decided pairs into `_labels` as training ground
 * truth (approve → 1, reject → 0). `unsure` rows are marked promoted without
 * writing a label, because an ambiguous verdict would poison the trainer.
 *
 * A rejection targets one candidate pair and is unique on
 * `(base_source, base_id, candidate_source, candidate_id)` — the same key shape
 * as `_labels` — so repeated votes update rather than duplicate.
 */
final class RejectionStore
{
    public const COLLECTION = 'pair_rejections';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const UNSURE = 'unsure';

    public const DECISIONS = [self::APPROVE, self::REJECT, self::UNSURE];

    public function __construct(
        private Database $db,
        private string $collection = self::COLLECTION,
    ) {
    }

    public static function create(Database $db, string $collection = self::COLLECTION): self
    {
        return new self($db, $collection);
    }

    private function collection(): Collection
    {
        return $this->db->getCollection($this->collection);
    }

    public function db(): Database
    {
        return $this->db;
    }

    /**
     * Enforce the full-pair unique key at the database level. Idempotent and
     * safe to call on every write; the collection is empty until first use.
     */
    public function ensureIndexes(): void
    {
        try {
            $this->collection()->createIndex(
                ['base_source' => 1, 'base_id' => 1, 'candidate_source' => 1, 'candidate_id' => 1],
                ['name' => 'rejection_pair', 'unique' => true],
            );
        } catch (\MongoDB\Driver\Exception\RuntimeException) {
            // already exists — fine
        }
    }

    /**
     * Required: `base_source`, `base_id`, `candidate_source`, `candidate_id`.
     * Optional: `decision` (approve|reject|unsure, default reject), `source`,
     * `notes`, `score` (the metadata/ML score at rejection time).
     *
     * @param array<string, mixed> $rejection
     */
    public function upsert(array $rejection): bool
    {
        $baseSource = trim((string) ($rejection['base_source'] ?? ''));
        $baseId = trim((string) ($rejection['base_id'] ?? ''));
        $candidateSource = trim((string) ($rejection['candidate_source'] ?? ''));
        $candidateId = trim((string) ($rejection['candidate_id'] ?? ''));
        if ($baseSource === '' || $baseId === '' || $candidateSource === '' || $candidateId === '') {
            return false;
        }

        $decision = (string) ($rejection['decision'] ?? self::REJECT);
        if (!in_array($decision, self::DECISIONS, true)) {
            $decision = self::REJECT;
        }

        $set = [
            'subject' => 'pair',
            'base_source' => $baseSource,
            'base_id' => $baseId,
            'candidate_source' => $candidateSource,
            'candidate_id' => $candidateId,
            'decision' => $decision,
            'source' => (string) ($rejection['source'] ?? 'human'),
            'notes' => (string) ($rejection['notes'] ?? ''),
            'updated_at' => new UTCDateTime(),
        ];
        if (isset($rejection['score']) && is_numeric($rejection['score'])) {
            $set['score'] = (float) $rejection['score'];
        }

        $result = $this->collection()->updateOne(
            [
                'base_source' => $baseSource,
                'base_id' => $baseId,
                'candidate_source' => $candidateSource,
                'candidate_id' => $candidateId,
            ],
            ['$set' => $set, '$setOnInsert' => ['created_at' => new UTCDateTime(), 'promoted' => false]],
            ['upsert' => true],
        );

        return $result->isAcknowledged();
    }

    /**
     * Newest-first rejection rows, optionally filtered by decision.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $decision = null, int $limit = 50): array
    {
        $filter = [];
        if ($decision !== null && $decision !== '') {
            $filter['decision'] = $decision;
        }
        // `created_at` has millisecond resolution, so several buffered pairs can
        // share a timestamp; `_id` (monotonic within a process) breaks the tie so
        // "newest first" is deterministic rather than whatever the sort lands on.
        $cursor = $this->collection()->find($filter, [
            'sort' => ['created_at' => -1, '_id' => -1],
            'limit' => max(1, min(500, $limit)),
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
        $out = [];
        foreach ($cursor as $doc) {
            if (is_array($doc)) {
                $out[] = $doc;
            }
        }

        return $out;
    }

    public function count(?string $decision = null, ?bool $promoted = null): int
    {
        $filter = [];
        if ($decision !== null && $decision !== '') {
            $filter['decision'] = $decision;
        }
        if ($promoted !== null) {
            $filter['promoted'] = $promoted;
        }

        return $this->collection()->countDocuments($filter);
    }

    /**
     * @return array{total: int, by_decision: array<string, int>, by_source: array<string, int>, promoted: int, pending: int}
     */
    public function stats(): array
    {
        $byDecision = array_fill_keys(self::DECISIONS, 0);
        $bySource = [];
        $cursor = $this->collection()->aggregate([
            ['$group' => [
                '_id' => ['decision' => '$decision', 'source' => '$source'],
                'n' => ['$sum' => 1],
            ]],
        ], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        foreach ($cursor as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = is_array($row['_id'] ?? null) ? $row['_id'] : [];
            $decision = (string) ($id['decision'] ?? 'unknown');
            $source = (string) ($id['source'] ?? 'unknown');
            $n = (int) ($row['n'] ?? 0);
            $byDecision[$decision] = ($byDecision[$decision] ?? 0) + $n;
            $bySource[$source] = ($bySource[$source] ?? 0) + $n;
        }

        $promoted = $this->count(null, true);

        return [
            'total' => $this->count(),
            'by_decision' => $byDecision,
            'by_source' => $bySource,
            'promoted' => $promoted,
            'pending' => $this->count(null, false),
        ];
    }

    /**
     * Copy decided rejections into `_labels` as training ground truth.
     *
     * approve → label 1, reject → label 0. `unsure` rows are marked promoted
     * without writing a label (an ambiguous verdict would poison the trainer).
     *
     * @return array{promoted: int, labels: int}
     */
    public function promote(?string $decision = null, ?int $limit = null, ?string $labelCollection = null): array
    {
        $filter = ['promoted' => false];
        if ($decision !== null && $decision !== '') {
            $filter['decision'] = $decision;
        }
        $options = [
            'sort' => ['created_at' => 1, '_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        if ($limit !== null && $limit > 0) {
            $options['limit'] = $limit;
        }
        $cursor = $this->collection()->find($filter, $options);

        $labels = LabelStore::create($this->db, $labelCollection ?? LabelStore::COLLECTION);
        $promoted = 0;
        $written = 0;

        foreach ($cursor as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $decision = (string) ($doc['decision'] ?? self::REJECT);
            if (in_array($decision, [self::APPROVE, self::REJECT], true)) {
                $ok = $labels->upsert([
                    'base_source' => (string) ($doc['base_source'] ?? ''),
                    'base_id' => (string) ($doc['base_id'] ?? ''),
                    'candidate_source' => (string) ($doc['candidate_source'] ?? ''),
                    'candidate_id' => (string) ($doc['candidate_id'] ?? ''),
                    'label' => $decision === self::APPROVE ? 1 : 0,
                    'source' => 'rejection-buffer',
                    'notes' => (string) ($doc['notes'] ?? ''),
                ]);
                if ($ok) {
                    $written++;
                }
            }
            $this->collection()->updateOne(
                ['_id' => $doc['_id'] ?? null],
                ['$set' => ['promoted' => true, 'promoted_at' => new UTCDateTime()]],
            );
            $promoted++;
        }

        return ['promoted' => $promoted, 'labels' => $written];
    }
}
