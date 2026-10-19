<?php

declare(strict_types=1);

namespace Hiyori\Tests\Integration;

use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

/**
 * Two works can share one cross-source id — one MAL series entry can cover
 * several AniList manga. The unique identity index then refuses the second
 * row, and the allocator has to tell "same work, corrected link" apart from
 * "someone else's value" using the BASE's own key.
 *
 * Getting that wrong gives two different works the same hiyori_id.
 */
final class RegistryIdentityConflictTest extends TestCase
{
    private const DB = 'hiyori_test_registry_conflict';

    private Client $client;

    private Migrator $migrator;

    protected function setUp(): void
    {
        $_ENV['MONGODB_URL'] ??= $_SERVER['MONGODB_URL'] ?? 'mongodb://localhost:27017';
        $this->client = new Client($_ENV['MONGODB_URL']);
        $this->client->selectDatabase(self::DB)->drop();

        $db = new Database(SerializerBuilder::create()->build());
        $this->migrator = new Migrator($db, self::DB);
        $this->migrator->ensureRegistryIndexes();
    }

    protected function tearDown(): void
    {
        $this->client->selectDatabase(self::DB)->drop();
    }

    public function testACorrectedCrossSourceLinkKeepsTheSameId(): void
    {
        $first = $this->migrator->allocateId('manga', ['al_manga' => '30001', 'mal_manga' => '60772'], 'pok-oki', '', 'al_manga');

        // The same work, but the merge now names a different MAL entry.
        $again = $this->migrator->allocateId('manga', ['al_manga' => '30001', 'mal_manga' => '62209'], 'pok-oki', '', 'al_manga');

        $this->assertSame($first, $again, 'a corrected cross-source link must not change the id');

        $row = $this->client->selectDatabase(self::DB)->selectCollection('_hiyori_ids')->findOne(
            ['hiyori_id' => $first],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        );
        $this->assertIsArray($row);
        $this->assertSame('62209', (string) ($row['refs']['mal_manga'] ?? ''), 'the row must carry the corrected link');
    }

    public function testAValueBelongingToAnotherWorkIsNotTaken(): void
    {
        $first = $this->migrator->allocateId('manga', ['al_manga' => '30045', 'k_manga' => '119'], 'ichigo-100', '', 'al_manga');

        // A different AniList manga that also links to k_manga 119.
        $second = $this->migrator->allocateId('manga', ['al_manga' => '179033', 'k_manga' => '119'], 'vegas-kun', '', 'al_manga');

        $this->assertNotSame($first, $second, 'two different works must not share a hiyori_id');

        // k_manga is a franchise key, not an identity key, so more than one row
        // may point at it — that is what "one MAL series covers several AniList
        // manga" looks like. What must hold is that the BASE's own identity key
        // is claimed exactly once, and that the two works kept separate ids.
        $rows = $this->client->selectDatabase(self::DB)->selectCollection('_hiyori_ids')->find(
            [],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        )->toArray();
        $alOwners = 0;
        foreach ($rows as $row) {
            if ((string) ($row['refs']['al_manga'] ?? '') === '179033') {
                ++$alOwners;
            }
        }
        $this->assertSame(1, $alOwners, 'the base identity key must be claimed by exactly one row');
    }
}
