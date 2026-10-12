<?php

namespace Hiyori\Service;

use Hiyori\Helper;
use Hiyori\Models\Anime\Base\KitsuBase;
use Hiyori\Models\Common\Base as AnimeBaseModel;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Serializer;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\InsertOneResult;
use MongoDB\UpdateResult;

class Database
{
    private Client $client;
    public function __construct(
        private Serializer $serializer
    )
    {
        $this->connect();
    }

    public function connect(Client $client = null)
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
            ->findOne(['reference_ids.'.$sourceId => $id]);

        return !($response === null);
    }

    public function get(string $source, string $sourceId, string $id, ?bool $toArray = false): array|null|object
    {
        if ($toArray) {
            return $this->getCollection($source)
                ->findOne(
                    ['reference_ids.'.$sourceId => $id],
                    ['typeMap' => ['root' => 'array', 'document' => 'array']]
                );
        }

        return $this->getCollection($source)
            ->findOne(['reference_ids.'.$sourceId => $id]);
    }

    public function save(string $source, AnimeBaseModel $entry): InsertOneResult
    {
        $entry = $this->serializer
            ->toArray(
                $entry,
                (new SerializationContext())
                    ->setSerializeNull(true)
            );

        return $this->getCollection($source)
            ->insertOne($entry);
    }

    public function mergeIntoOrganized(
        AnimeBaseModel $entry,
        ?string $identifier = null,
        ?string $identifierValue = null): UpdateResult
    {
        $entry = $this->serializer
            ->toArray(
                $entry,
                (new SerializationContext())
                    ->setSerializeNull(true)
            );

        return $this->getCollection('organized')
            ->replaceOne(
                [
                    'reference_ids.'.$identifier => $identifierValue
                ],
                $entry,
                [
                    'upsert' => true
                ]
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
