<?php

namespace Hiyori\Service;

use Hiyori\Helper;
use Hiyori\Models\Common\Base as AnimeBaseModel;
use MongoDB\Client;
use MongoDB\InsertOneResult;

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

    public function exists(string $source, string $sourceId, string $id): bool
    {
        $response = $this->client->hiyori->{$source}
            ->findOne(['reference_ids.'.$sourceId => $id]);

        return !($response === null);
    }

    public function get(string $source, string $sourceId, string $id, ?bool $toArray = false): array|null|object
    {
        if ($toArray) {
            return $this->client->hiyori->{$source}
                ->findOne(
                    ['reference_ids.'.$sourceId => $id],
                    ['typeMap' => ['root' => 'array', 'document' => 'array']]
                );
        }

        return $this->client->hiyori->{$source}
            ->findOne(['reference_ids.'.$sourceId => $id]);
    }

    public function save(string $source, AnimeBaseModel $entry): InsertOneResult
    {
        $entry = $this->serializer->getSerializer()
            ->serialize($entry, 'json');

        return $this->client->hiyori->{$source}
            ->insertOne(Helper::toArray($entry));
    }

    public function mergeIntoOrganized(
        AnimeBaseModel $entry,
        ?string $identifier = null,
        ?string $identifierValue = null): \MongoDB\UpdateResult
    {
        $entry = $this->serializer->getSerializer()
            ->serialize($entry, 'json');

        return $this->client->hiyori->organized
            ->replaceOne(
                [
                    'reference_ids.'.$identifier => $identifierValue
                ],
                Helper::toArray($entry),
                [
                    'upsert' => true
                ]
            );
    }

}