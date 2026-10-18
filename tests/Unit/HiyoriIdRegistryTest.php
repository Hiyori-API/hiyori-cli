<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Pure (no-DB) coverage for the registry identity helpers introduced to make
 * `(kind, refs.<key>)` uniqueness checkable and allocation idempotent.
 */
final class HiyoriIdRegistryTest extends TestCase
{
    public function testIdentityRefsKeepsOnlyIdentityKeys(): void
    {
        $this->assertSame(
            ['mal' => '5', 'al' => '1'],
            Migrator::identityRefs(['mal' => 5, 'cr' => 'show', 'al' => 1, 'k' => '9']),
        );
        $this->assertSame([], Migrator::identityRefs(['cr' => 'show', 'k' => '9']));
    }

    public function testDuplicateIdentityRefValuesCountsPerKind(): void
    {
        $rows = [
            // The live contamination pattern: two anime ids both claiming mal 1519.
            ['hiyori_id' => 1342, 'kind' => 'anime', 'refs' => ['al' => '1519', 'mal' => '1519']],
            ['hiyori_id' => 5729, 'kind' => 'anime', 'refs' => (object) ['al' => '123074', 'mal' => '1519']],
            // Same value on a different kind is not a conflict: ids only need
            // to be unique within a kind.
            ['hiyori_id' => 9, 'kind' => 'manga', 'refs' => ['mal' => '1519', 'mal_manga' => '1519']],
        ];

        $report = Migrator::duplicateIdentityRefValues($rows, 'mal');

        $this->assertSame(1, $report['duplicates']);
        $this->assertSame(2, $report['values']);
        $this->assertSame(2, $report['max']);
        $this->assertSame(['anime' => 1], $report['by_kind']);
        $this->assertCount(1, $report['examples']);
        $this->assertSame('anime', $report['examples'][0]['kind']);
        $this->assertSame('1519', $report['examples'][0]['value']);
        $this->assertEqualsCanonicalizing([1342, 5729], $report['examples'][0]['hiyori_ids']);
    }

    public function testDuplicateIdentityRefValuesIgnoresFranchiseKeysAndEmptyValues(): void
    {
        $rows = [
            ['hiyori_id' => 1, 'kind' => 'anime', 'refs' => ['cr' => 'show', 'mal' => '']],
            ['hiyori_id' => 2, 'kind' => 'anime', 'refs' => ['cr' => 'show', 'mal' => '']],
        ];

        // `cr` is a franchise key and repeated here on purpose; it must never
        // be treated as identity, and empty identity values are ignored.
        foreach (['mal', 'al', 'mal_manga', 'al_manga'] as $key) {
            $report = Migrator::duplicateIdentityRefValues($rows, $key);
            $this->assertSame(0, $report['duplicates'], $key);
        }
    }

    public function testIdentityRefHoldersIsolatesKindsAndValues(): void
    {
        $rows = [
            ['hiyori_id' => 1, 'kind' => 'anime', 'refs' => ['al' => '10']],
            ['hiyori_id' => 2, 'kind' => 'anime', 'refs' => ['al' => '10']],
            ['hiyori_id' => 3, 'kind' => 'manga', 'refs' => ['al' => '10']],
            ['hiyori_id' => 4, 'kind' => 'anime', 'refs' => 'not-a-map'],
        ];

        $holders = Migrator::identityRefHolders($rows, ['al']);

        $this->assertSame([1, 2], $holders['al']['anime']['10']);
        $this->assertSame([3], $holders['al']['manga']['10']);
    }

    public function testResolveDuplicateHoldersRankThenSmallestId(): void
    {
        // rank 1 (base corroborated) wins over rank 2 even with a larger id.
        $decision = Migrator::resolveDuplicateHolders([
            ['hiyori_id' => 500, 'rank' => 2],
            ['hiyori_id' => 10, 'rank' => 1],
            ['hiyori_id' => 7, 'rank' => 2],
        ]);
        $this->assertSame(10, $decision['keep']);
        $this->assertSame([7, 500], $decision['dropped']);

        // Equal rank falls back to the smallest id.
        $tie = Migrator::resolveDuplicateHolders([
            ['hiyori_id' => 42, 'rank' => 2],
            ['hiyori_id' => 9, 'rank' => 2],
        ]);
        $this->assertSame(9, $tie['keep']);
        $this->assertSame([42], $tie['dropped']);

        // Missing rank defaults to the top priority.
        $default = Migrator::resolveDuplicateHolders([
            ['hiyori_id' => 50],
            ['hiyori_id' => 5, 'rank' => 2],
        ]);
        $this->assertSame(50, $default['keep']);
    }

    public function testResolveDuplicateHoldersIsOrderIndependent(): void
    {
        $holders = [
            ['hiyori_id' => 3, 'rank' => 2],
            ['hiyori_id' => 1, 'rank' => 0],
            ['hiyori_id' => 2, 'rank' => 1],
        ];
        $expected = Migrator::resolveDuplicateHolders($holders);

        foreach ([array_reverse($holders), [$holders[1], $holders[2], $holders[0]]] as $shuffled) {
            $this->assertSame($expected, Migrator::resolveDuplicateHolders($shuffled));
        }
    }

    public function testResolveDuplicateHoldersRejectsEmptyInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Migrator::resolveDuplicateHolders([]);
    }

    public function testNormalizedRefsAreOrderIndependentForUpsertKey(): void
    {
        // allocateRegistryId() upserts on `refs`, and BSON sub-document
        // equality is field-order sensitive. normalizeRefs() ksort()s so a
        // retry of the same document always builds the same filter.
        $this->assertSame(
            Migrator::normalizeRefs(['mal' => '5', 'al' => '1', 'cr' => 'show']),
            Migrator::normalizeRefs(['cr' => 'show', 'al' => '1', 'mal' => '5']),
        );
    }
}
