<?php

namespace Hiyori\Service\Pipeline;

use Hiyori\Service\Migrator;
use MongoDB\Client;

/**
 * MongoDB-backed preconditions. Every method here issues queries only; none of
 * them writes, which is what lets `--dry-run` evaluate them safely.
 */
final class DatabaseChecks implements PipelineChecks
{
    public function __construct(
        private Client $client,
        private string $database,
        private Migrator $migrator,
    ) {
    }

    public function database(): string
    {
        return $this->database;
    }

    public function databaseReachable(): PreconditionResult
    {
        try {
            $this->client->selectDatabase($this->database)->command(['ping' => 1])->toArray();
        } catch (\Throwable $e) {
            return PreconditionResult::fail('database_reachable', 'MongoDB is unreachable: ' . $e->getMessage());
        }

        return PreconditionResult::pass('database_reachable', sprintf('MongoDB at %s answered ping', $this->database));
    }

    public function noPendingMigrations(): PreconditionResult
    {
        $pending = $this->migrator->pending();
        if ($pending === []) {
            return PreconditionResult::pass('no_pending_migrations', 'no migrations pending');
        }

        $names = [];
        foreach ($pending as $version => $name) {
            $names[] = sprintf('v%s %s', $version, $name);
        }

        return PreconditionResult::fail(
            'no_pending_migrations',
            'pending migrations must be applied before combining: ' . implode(', ', $names),
        );
    }

    public function collectionHasDocuments(string $collection): PreconditionResult
    {
        $name = 'collection_not_empty:' . $collection;

        try {
            $count = $this->client->selectDatabase($this->database)->selectCollection($collection)->countDocuments();
        } catch (\Throwable $e) {
            return PreconditionResult::fail($name, sprintf('could not count %s: %s', $collection, $e->getMessage()));
        }

        if ($count > 0) {
            return PreconditionResult::pass($name, sprintf('%s holds %d documents', $collection, $count));
        }

        return PreconditionResult::fail(
            $name,
            sprintf('%s is empty — a gate or backfill over it would prove nothing', $collection),
        );
    }
}
