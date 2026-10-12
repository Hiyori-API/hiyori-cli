<?php

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

class IngestionTest extends TestCase
{
    private static Database $db;
    private static Client $client;

    public static function setUpBeforeClass(): void
    {
        $dotenv = new \Symfony\Component\Dotenv\Dotenv();
        $dotenv->load(__DIR__.'/../../.env');
        
        self::$client = new Client($_ENV['MONGODB_URL']);
        $serializer = SerializerBuilder::create()->build();
        self::$db = new Database($serializer);
    }

    public function testAniListEntryListFetch(): void
    {
        $page = 1;
        $list = \Hiyori\Requests\AniList\AniListEntryList::create($page);
        $entries = $list->getList();
        
        $this->assertNotEmpty($entries);
        $this->assertArrayHasKey('id', $entries[0]);
        $this->assertArrayHasKey('title', $entries[0]);
    }

    public function testAniListEntryListMetaFetch(): void
    {
        $meta = \Hiyori\Requests\AniList\AniListEntryListMeta::create();
        $this->assertGreaterThan(0, $meta->getTotalEntries());
        $this->assertGreaterThan(0, $meta->getLastPage());
    }

    public function testKitsuEntryListFetch(): void
    {
        $page = 0;
        $list = \Hiyori\Requests\Kitsu\KitsuEntryList::create($page);
        $entries = $list->getList();
        
        $this->assertNotEmpty($entries);
        $this->assertArrayHasKey('id', $entries[0]);
        $this->assertArrayHasKey('attributes', $entries[0]);
    }

    public function testKitsuEntryListMetaFetch(): void
    {
        $meta = \Hiyori\Requests\Kitsu\KitsuEntryListMeta::create();
        $this->assertGreaterThan(0, $meta->getTotalEntries());
    }

    public function testKitsuAdditionalFetches(): void
    {
        $page = 0;
        $list = \Hiyori\Requests\Kitsu\KitsuEntryList::create($page);
        $firstId = $list->getList()[0]['id'];
        
        $mappings = \Hiyori\Requests\Kitsu\KitsuEntryMappings::create($firstId);
        $this->assertIsArray($mappings->getData());
        $this->assertNotEmpty($mappings->getData());
        
        $streaming = \Hiyori\Requests\Kitsu\KitsuEntryStreamingLinks::create($firstId);
        $this->assertIsArray($streaming->getData());
    }

    public function testDatabaseConnection(): void
    {
        $client = self::$db->getClient();
        $dbs = $client->listDatabases();
        $dbNames = [];
        foreach ($dbs as $db) {
            $dbNames[] = $db->getName();
        }
        $this->assertContains('hiyori', $dbNames);
    }

    public function testSourceCollectionsExist(): void
    {
        $collections = self::$client->hiyori->listCollections();
        $names = [];
        foreach ($collections as $col) {
            $names[] = $col->getName();
        }
        $this->assertContains('anilist', $names);
        $this->assertContains('kitsu', $names);
        $this->assertContains('myanimelist', $names);
    }
}
