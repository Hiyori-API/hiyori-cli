<?php

namespace Hiyori\Service\Ingestion;

use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use MongoDB\Collection;

trait IngestionTraits
{
    /**
     * Execute a callable with retry logic and exponential backoff.
     */
    protected function withRetry(callable $callback, int $maxRetries = 3, int $baseDelayMs = 1000)
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (\Exception $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    $delay = $baseDelayMs * (2 ** ($attempt - 1));
                    $this->console->io()->info(
                        "Attempt $attempt/$maxRetries failed: {$e->getMessage()}. Retrying in {$delay}ms...",
                    );
                    usleep($delay * 1000);
                }
            }
        }

        throw $lastException ?? new \Exception('Retry exhausted');
    }

    /**
     * Save or update an entry in the database.
     */
    protected function saveOrUpdate(
        Database $db,
        SourceConfigurationFactory $sources,
        string $table,
        \Hiyori\Models\Common\Base $entry,
        bool $updateExisting = false
    ) {
        $data = $this->serializeEntry($db, $entry);
        $shorthand = $sources->get()->getShorthand();
        $jsonId = $sources->get()->getJsonId();
        $idValue = $data['reference_ids'][$shorthand] ?? null;

        if ($updateExisting && $idValue !== null) {
            /** @var Collection $collection */
            $collection = $db->getCollection($table);

            return $collection->replaceOne(
                ['reference_ids.' . $shorthand => $idValue],
                $data,
                ['upsert' => true],
            );
        }

        return $db->save($table, $entry);
    }

    /**
     * Check if entry exists and optionally get it for update comparison.
     */
    protected function checkExists(
        Database $db,
        SourceConfigurationFactory $sources,
        string $table,
        string $shorthand,
        string $id,
        bool $forUpdate = false
    ): ?array {
        if ($forUpdate) {
            return $db->get($table, $shorthand, $id, true);
        }

        return $db->exists($table, $shorthand, $id) ? ['exists' => true] : null;
    }

    /**
     * Save progress for resumability.
     */
    protected function saveProgress(string $sourceTable, int $page): void
    {
        $progressFile = sys_get_temp_dir() . "/hiyori_ingest_progress_{$sourceTable}.json";
        file_put_contents($progressFile, json_encode([
            'source' => $sourceTable,
            'last_page' => $page,
            'timestamp' => time(),
        ]));
    }

    /**
     * Load progress for resumability.
     */
    protected function loadProgress(string $sourceTable): ?int
    {
        $progressFile = sys_get_temp_dir() . "/hiyori_ingest_progress_{$sourceTable}.json";
        if (!file_exists($progressFile)) {
            return null;
        }
        $data = json_decode(file_get_contents($progressFile), true);

        return $data['last_page'] ?? null;
    }

    private function serializeEntry(Database $db, \Hiyori\Models\Common\Base $entry): array
    {
        $serializer = $db->getSerializer();

        return $serializer->toArray($entry, (new \JMS\Serializer\SerializationContext())->setSerializeNull(true));
    }
}
