<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Database;
use Hiyori\Service\Migrator;
use JMS\Serializer\SerializerBuilder;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private function migrator(?array $applied): Migrator
    {
        $_ENV['MONGODB_URL'] ??= 'mongodb://localhost:27017';
        $db = new Database(SerializerBuilder::create()->build());

        return new Migrator($db, 'hiyori_test_mig', $applied);
    }

    public function testVersionsAreOrderedAndKnown(): void
    {
        $m = $this->migrator([]);
        $versions = $m->versions();
        $this->assertContains(1, array_keys($versions));
        $this->assertContains(2, array_keys($versions));
        $this->assertContains(3, array_keys($versions));
        $this->assertContains(4, array_keys($versions));
        $this->assertSame('indexes_candidates_and_references', $versions[1]);
        $this->assertSame('ensure_organized_collections', $versions[2]);
        $this->assertSame('allocate_hiyori_ids', $versions[3]);
        $this->assertSame('ml_fields_and_indexes', $versions[4]);
    }

    public function testPendingReturnsAllWhenNothingApplied(): void
    {
        $m = $this->migrator([]);
        $this->assertSame(array_keys($m->versions()), array_keys($m->pending()));
    }

    public function testPendingSkipsApplied(): void
    {
        $m = $this->migrator([1 => 'indexes_candidates_and_references']);
        // Derived from the migration list, so a new migration does not make
        // this test wrong.
        $all = array_keys($m->versions());
        $this->assertSame(array_slice($all, 1), array_keys($m->pending()));
        $this->assertSame(array_values(array_slice($m->versions(), 1, null, true)), array_values($m->pending()));
    }

    public function testPendingEmptyWhenFullyApplied(): void
    {
        $probe = $this->migrator([]);
        $all = [];
        foreach (array_keys($probe->versions()) as $v) {
            $all[$v] = 'applied';
        }
        $m = $this->migrator($all);
        $this->assertSame([], $m->pending());
    }
}
