<?php

namespace Hiyori\Service;

use Hiyori\Models\Common\Base as AnimeBaseModel;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Serializer;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\InsertOneResult;
use MongoDB\UpdateResult;

class Database
{
    private Client $client;

    private Validator $validator;

    private ?Migrator $migrator = null;

    public function __construct(
        private Serializer $serializer,
        ?Validator $validator = null,
        ?Migrator $migrator = null
    ) {
        $this->validator = $validator ?? new Validator();
        $this->migrator = $migrator;
        $this->connect();
    }

    public function setMigrator(Migrator $migrator): void
    {
        $this->migrator = $migrator;
    }

    public function getMigrator(): ?Migrator
    {
        return $this->migrator;
    }

    public function getValidator(): Validator
    {
        return $this->validator;
    }

    public function connect(?Client $client = null)
    {
        $this->client = $client ?? new Client($_ENV['MONGODB_URL']);
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @return Serializer
     */
    public function getSerializer(): Serializer
    {
        return $this->serializer;
    }

    /**
     * Get a collection by name
     */
    public function getCollection(string $name): Collection
    {
        /** @phpstan-ignore-next-line */
        return $this->client->hiyori->{$name};
    }

    public function exists(string $source, string $sourceId, string $id): bool
    {
        $response = $this->getCollection($source)
            ->findOne(['reference_ids.' . $sourceId => $id]);

        return !($response === null);
    }

    public function get(string $source, string $sourceId, string $id, ?bool $toArray = false): array|null|object
    {
        if ($toArray) {
            return $this->getCollection($source)
                ->findOne(
                    ['reference_ids.' . $sourceId => $id],
                    ['typeMap' => ['root' => 'array', 'document' => 'array']],
                );
        }

        return $this->getCollection($source)
            ->findOne(['reference_ids.' . $sourceId => $id]);
    }

    public function save(string $source, AnimeBaseModel $entry): InsertOneResult
    {
        $entry = $this->serializer
            ->toArray(
                $entry,
                (new SerializationContext())
                    ->setSerializeNull(true),
            );

        // Validate before persisting; log violations but keep the record moving.
        $errors = $this->validator->validate($entry);
        if ($errors !== []) {
            $summary = [];
            foreach ($errors as $field => $messages) {
                $summary[] = $field . '=' . implode(',', $messages);
            }
            $message = sprintf('[validator] %s entry validation issues: %s', $source, implode('; ', $summary));
            error_log($message);
            (new \Hiyori\Service\Telemetry())->append('validation-log', [
                'source' => $source,
                'title' => $entry['title'] ?? null,
                'errors' => $errors,
            ]);
        }

        return $this->getCollection($source)
            ->insertOne($entry);
    }

    /**
     * Validate a raw payload before transformation.
     */
    public function validatePayload(array $payload): array
    {
        return $this->validator->validatePayload($payload);
    }

    /**
     * Allocate a stable Hiyori ID for a new organized entry.
     * Reuses existing ID if the reference set is already known.
     *
     * @param string $kind          'anime' or 'manga'
     * @param array<string, string> $refs   Reference map (e.g., ['al' => '1', 'mal' => '1'])
     * @param string $slug          Title slug
     * @param string $legacyId      Previous MongoDB _id (optional)
     * @return int                   The allocated hiyori_id
     */
    public function allocateHiyoriId(string $kind, array $refs, string $slug, string $legacyId = ''): int
    {
        if ($this->migrator === null) {
            throw new \RuntimeException('Migrator not available — cannot allocate hiyori_id');
        }
        return $this->migrator->allocateId($kind, $refs, $slug, $legacyId);
    }

    public function mergeIntoOrganized(
        AnimeBaseModel $entry,
        ?string $identifier = null,
        ?string $identifierValue = null,
        string $collection = 'organized',
        array $extra = []
    ): UpdateResult {
        $entry = $this->serializer
            ->toArray(
                $entry,
                (new SerializationContext())
                    ->setSerializeNull(true),
            );

        if ($extra !== []) {
            $entry = array_merge($entry, $extra);
        }

        return $this->getCollection($collection)
            ->replaceOne(
                [
                    'reference_ids.' . $identifier => $identifierValue,
                ],
                $entry,
                [
                    'upsert' => true,
                ],
            );
    }

    /**
     * Get all unique reference IDs from the organized collection.
     *
     * Returns an associative array where keys are the reference shorthands
     * (e.g., 'mal', 'al', 'k', 'adb', etc.) and values are arrays of unique IDs.
     *
     * @return array<string, array<string>>
     */
    public function getUniqueReferences(): array
    {
        $cursor = $this->getCollection('organized')
            ->find([], ['projection' => ['reference_ids' => 1, '_id' => 0]]);

        $uniqueRefs = [];

        foreach ($cursor as $doc) {
            if (!isset($doc['reference_ids']) || !is_array($doc['reference_ids'])) {
                continue;
            }

            foreach ($doc['reference_ids'] as $shorthand => $id) {
                if (!isset($uniqueRefs[$shorthand])) {
                    $uniqueRefs[$shorthand] = [];
                }
                $uniqueRefs[$shorthand][$id] = true;
            }
        }

        foreach ($uniqueRefs as $shorthand => $ids) {
            $uniqueRefs[$shorthand] = array_keys($ids);
        }

        return $uniqueRefs;
    }
}
