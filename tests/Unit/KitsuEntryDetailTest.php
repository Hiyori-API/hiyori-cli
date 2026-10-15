<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Requests\Kitsu\KitsuEntryDetail;
use PHPUnit\Framework\TestCase;

final class KitsuEntryDetailTest extends TestCase
{
    public function testHydrateFoldsRelationshipsIntoExpectedShape(): void
    {
        $response = [
            'data' => [
                'id' => '1',
                'type' => 'anime',
                'attributes' => ['canonicalTitle' => 'Cowboy Bebop'],
                'relationships' => [
                    'mappings' => ['data' => [['id' => '10', 'type' => 'mappings']]],
                    'streamingLinks' => ['data' => [['id' => '20', 'type' => 'streamingLinks']]],
                    'genres' => ['data' => [['id' => '30', 'type' => 'genres']]],
                    'categories' => ['data' => [['id' => '40', 'type' => 'categories']]],
                ],
            ],
            'included' => [
                ['id' => '10', 'type' => 'mappings', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '1']],
                ['id' => '20', 'type' => 'streamingLinks', 'attributes' => ['url' => 'https://example/stream']],
                ['id' => '30', 'type' => 'genres', 'attributes' => ['name' => 'Action']],
                ['id' => '40', 'type' => 'categories', 'attributes' => ['title' => 'Space']],
            ],
        ];

        $hydrated = KitsuEntryDetail::hydrate($response);

        $this->assertSame('1', $hydrated['id']);
        $this->assertSame('myanimelist/anime', $hydrated['external'][0]['attributes']['externalSite']);
        $this->assertSame('https://example/stream', $hydrated['streaming'][0]['attributes']['url']);
        $this->assertSame('Action', $hydrated['genres'][0]['attributes']['name']);
        $this->assertSame('Space', $hydrated['categories'][0]['attributes']['title']);
    }

    public function testHydrateIgnoresDanglingRelationshipRefs(): void
    {
        $response = [
            'data' => [
                'id' => '2',
                'type' => 'manga',
                'attributes' => [],
                'relationships' => [
                    'mappings' => ['data' => [['id' => '999', 'type' => 'mappings']]],
                ],
            ],
            'included' => [],
        ];

        $hydrated = KitsuEntryDetail::hydrate($response);

        $this->assertSame([], $hydrated['external']);
        $this->assertSame([], $hydrated['streaming']);
        $this->assertSame([], $hydrated['genres']);
        $this->assertSame([], $hydrated['categories']);
    }
}
