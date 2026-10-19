<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Coverage;
use PHPUnit\Framework\TestCase;

/**
 * The coverage matrix is the dashboard's most expensive read and used to have
 * no test at all, because it lived in the front controller — a file that
 * cannot be included cannot be tested. These are the parts that need no
 * database; the aggregation itself is covered by the integration test.
 */
final class CoverageTest extends TestCase
{
    public function testPipelineNormalisesReferenceIdsToKeysAndGroupsThem(): void
    {
        $pipeline = Coverage::pipeline();

        $this->assertCount(3, $pipeline);
        $this->assertSame(['$set', '$unwind', '$group'], array_map(
            static fn (array $stage): string => (string) array_key_first($stage),
            $pipeline,
        ));
    }

    public function testPipelineUnwindsAwayDocumentsWithoutReferences(): void
    {
        $pipeline = Coverage::pipeline();
        $unwind = $pipeline[1]['$unwind'];

        $this->assertIsArray($unwind);
        $this->assertSame('$_keys', $unwind['path']);
        // A document with no reference_ids must not be counted for anything.
        $this->assertFalse($unwind['preserveNullAndEmptyArrays']);
    }

    public function testShorthandsCoverTheKnownSources(): void
    {
        $this->assertContains('al', Coverage::SHORTHANDS);
        $this->assertContains('mal', Coverage::SHORTHANDS);
        $this->assertContains('k', Coverage::SHORTHANDS);
        $this->assertSame(['al', 'mal', 'k'], Coverage::MAIN_SHORTHANDS);
        // No duplicates: a duplicated shorthand would be counted twice.
        $this->assertSame(count(Coverage::SHORTHANDS), count(array_unique(Coverage::SHORTHANDS)));
    }

    public function testSourceTableMapsShorthandsToTheSourceCollection(): void
    {
        $this->assertSame('anilist', Coverage::sourceTable('al'));
        $this->assertSame('myanimelist', Coverage::sourceTable('mal'));
        $this->assertSame('kitsu', Coverage::sourceTable('k'));
    }

    public function testSourceTableIsNullForAnUnknownShorthand(): void
    {
        $this->assertNull(Coverage::sourceTable('zz'));
    }
}
