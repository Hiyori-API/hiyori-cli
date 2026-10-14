<?php

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private const DB = 'hiyori_test_migrator';

    public function testMigrationsRunIdempotentlyAndIndexesAreCreated(): void
    {
        $_ENV['MONGODB_URL'] ??= $_SERVER['MONGODB_URL'] ?? 'mongodb://localhost:27017';
        $client = new Client($_ENV['MONGODB_URL']);
        $client->selectDatabase(self::DB)->drop();

        $content = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $db = new Database(SerializerBuilder::create()->build());
        $migrator = new \Hiyori\Service\Migrator($db, self::DB);

        // nothing applied yet
        $this->assertNotEmpty($migrator->pending());

        $applied = $migrator->run();
        $this->assertSame(array_keys($migrator->versions()), array_keys($applied));

        // second run is a no-op
        $this->assertSame([], $migrator->run());
        $this->assertSame([], $migrator->pending());

        $indexes = $client->selectDatabase(self::DB)->selectCollection('organized')->listIndexes();
        $names = [];
        foreach ($indexes as $i) {
            $names[] = $i->getName();
        }
        $this->assertContains('org_cand', $names);
        $this->assertContains('org_ref_al', $names);

        $mangaIndexes = $client->selectDatabase(self::DB)->selectCollection('organized_manga')->listIndexes();
        $mangaNames = [];
        foreach ($mangaIndexes as $i) {
            $mangaNames[] = $i->getName();
        }
        $this->assertContains('mg_ref_k_manga', $mangaNames);

        $animeIndexes = $client->selectDatabase(self::DB)->selectCollection('organized')->listIndexes();
        $animeNames = [];
        foreach ($animeIndexes as $i) {
            $animeNames[] = $i->getName();
        }
        $this->assertContains('hiyori_id_1', $animeNames);

        $log = $client->selectDatabase(self::DB)->selectCollection('_migrations')->countDocuments();
        $this->assertSame(3, $log);

        $client->selectDatabase(self::DB)->drop();
    }
}
