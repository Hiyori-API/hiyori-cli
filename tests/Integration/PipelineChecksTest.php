<?php

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use Hiyori\Service\Pipeline\DatabaseChecks;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class PipelineChecksTest extends TestCase
{
    private const DB = 'hiyori_test_pipeline';

    private Client $client;

    protected function setUp(): void
    {
        $_ENV['MONGODB_URL'] ??= $_SERVER['MONGODB_URL'] ?? 'mongodb://localhost:27017';
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->selectDatabase(self::DB)->drop();
    }

    protected function tearDown(): void
    {
        $this->client->selectDatabase(self::DB)->drop();
    }

    private function checks(Migrator $migrator): DatabaseChecks
    {
        return new DatabaseChecks($this->client, self::DB, $migrator);
    }

    private function migrator(): Migrator
    {
        return new Migrator(new Database(SerializerBuilder::create()->build()), self::DB);
    }

    public function testDatabaseIsReachable(): void
    {
        $result = $this->checks($this->migrator())->databaseReachable();

        $this->assertTrue($result->ok);
        $this->assertSame('database_reachable', $result->name);
    }

    public function testEmptyCollectionFailsTheGatePrecondition(): void
    {
        $result = $this->checks($this->migrator())->collectionHasDocuments('organized');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('empty', $result->detail);
    }

    public function testPopulatedCollectionPasses(): void
    {
        $this->client->selectDatabase(self::DB)->selectCollection('organized')->insertOne(['title' => 'A', 'hiyori_id' => 1]);

        $result = $this->checks($this->migrator())->collectionHasDocuments('organized');

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('1 documents', $result->detail);
    }

    public function testPendingMigrationsAreReportedThenCleared(): void
    {
        $migrator = $this->migrator();
        $checks = $this->checks($migrator);

        $before = $checks->noPendingMigrations();
        $this->assertFalse($before->ok);
        $this->assertStringContainsString('pending migrations', $before->detail);

        $migrator->run();

        $after = $checks->noPendingMigrations();
        $this->assertTrue($after->ok);
    }
}
