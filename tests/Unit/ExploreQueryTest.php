<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Explorer;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use PHPUnit\Framework\TestCase;

final class ExploreQueryTest extends TestCase
{
    public function testBuildFilterSearchCreatesOrClause(): void
    {
        $f = Explorer::buildFilter(['q' => 'cowboy']);
        $this->assertArrayHasKey('$or', $f);
        $this->assertIsArray($f['$or']);
        $first = $f['$or'][0] ?? null;
        $this->assertArrayHasKey('title', $first);
        $this->assertInstanceOf(Regex::class, $first['title']);
    }

    public function testBuildFilterPrebuiltMissingRef(): void
    {
        $f = Explorer::buildFilter(['filter' => 'no_mal']);
        $this->assertSame(['reference_ids.mal' => ['$exists' => false]], $f);
    }

    public function testBuildFilterEpisodesRange(): void
    {
        $f = Explorer::buildFilter(['episodes_min' => 12, 'episodes_max' => 26]);
        $this->assertSame(['episodes' => ['$gte' => 12, '$lte' => 26]], $f);
    }

    public function testBuildFilterEpisodesMinOnly(): void
    {
        $f = Explorer::buildFilter(['episodes_min' => 12]);
        $this->assertSame(['episodes' => ['$gte' => 12]], $f);
    }

    public function testBuildFilterYearRangeMapsYearAndStartDate(): void
    {
        $f = Explorer::buildFilter(['year_min' => 1998]);
        $this->assertSame(
            ['$or' => [['year' => ['$gte' => 1998]], ['start_date.year' => ['$gte' => 1998]]]],
            $f,
        );
    }

    public function testBuildFilterCombinesSearchAndRangeWithAnd(): void
    {
        $f = Explorer::buildFilter(['q' => 'four', 'year_min' => 2000, 'year_max' => 2010]);
        $this->assertArrayHasKey('$and', $f);
        $this->assertCount(2, $f['$and']);
        $this->assertArrayHasKey('$or', $f['$and'][0]);
        $this->assertSame(
            ['$or' => [
                ['year' => ['$gte' => 2000, '$lte' => 2010]],
                ['start_date.year' => ['$gte' => 2000, '$lte' => 2010]],
            ]],
            $f['$and'][1],
        );
    }

    public function testBuildFilterCombinesPrebuiltFilterAndRange(): void
    {
        $f = Explorer::buildFilter(['filter' => 'no_mal', 'episodes_max' => 13]);
        $this->assertSame(
            ['$and' => [
                ['reference_ids.mal' => ['$exists' => false]],
                ['episodes' => ['$lte' => 13]],
            ]],
            $f,
        );
    }

    public function testBuildFilterIgnoresNonNumericRanges(): void
    {
        $this->assertSame([], Explorer::buildFilter(['episodes_min' => 'abc']));
        $this->assertSame([], Explorer::buildFilter(['episodes_min' => '', 'year_max' => null]));
        $this->assertSame([], Explorer::buildFilter([]));
    }

    public function testFieldPathValidation(): void
    {
        $this->assertSame('start_date.year', Explorer::fieldPath('start_date.year'));
        $this->assertSame('episodes', Explorer::fieldPath('episodes'));
        $this->assertSame('', Explorer::fieldPath('a b'));
        $this->assertSame('', Explorer::fieldPath('a$b'));
        $this->assertSame('', Explorer::fieldPath('a-b'));
        $this->assertSame('', Explorer::fieldPath(''));
    }

    public function testCollectionNameValidation(): void
    {
        $this->assertSame('organized', Explorer::collectionName('organized'));
        $this->assertSame('organized_manga', Explorer::collectionName('organized_manga'));
        $this->assertSame('', Explorer::collectionName('bad name'));
        $this->assertSame('', Explorer::collectionName('a/b'));
    }

    public function testDocIdValueConvertsHexToObjectId(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        $v = Explorer::docIdValue($hex);
        $this->assertInstanceOf(ObjectId::class, $v);
        $this->assertSame($hex, (string) $v);
        $this->assertSame('non-hex-id', Explorer::docIdValue('non-hex-id'));
        $this->assertSame('507f1f77bcf86cd79943901', Explorer::docIdValue('507f1f77bcf86cd79943901')); // 23 chars → string
    }

    public function testFacetPipelineShape(): void
    {
        $p = Explorer::facetPipeline('status', []);
        $this->assertCount(4, $p);
        $this->assertSame(['status' => ['$type' => 'string', '$nin' => ['']]], $p[0]['$match']);
        $this->assertSame(['_id' => '$status', 'n' => ['$sum' => 1]], $p[1]['$group']);
        $this->assertSame(['n' => -1], $p[2]['$sort']);
        $this->assertSame(Explorer::MAX_FACET_VALUES, $p[3]['$limit']);
    }

    public function testFacetPipelineHonoursExistingFilter(): void
    {
        $p = Explorer::facetPipeline('format', ['reference_ids.mal' => ['$exists' => false]]);
        $this->assertSame(
            ['$and' => [
                ['reference_ids.mal' => ['$exists' => false]],
                ['format' => ['$type' => 'string', '$nin' => ['']]],
            ]],
            $p[0]['$match'],
        );
    }

    public function testParseCsvSimple(): void
    {
        $rows = Explorer::parseCsv("title,status\nCowboy,finished\nAkira,finished\n");
        $this->assertSame([
            ['title' => 'Cowboy', 'status' => 'finished'],
            ['title' => 'Akira', 'status' => 'finished'],
        ], $rows);
    }

    public function testParseCsvQuotedFieldsAndCrlf(): void
    {
        $csv = "title,desc\r\n\"Cowboy Bebop\",\"Space, cowboy\"\r\n\"A\",\"x\"\r\n";
        $rows = Explorer::parseCsv($csv);
        $this->assertSame('Cowboy Bebop', $rows[0]['title']);
        $this->assertSame('Space, cowboy', $rows[0]['desc']);
        $this->assertSame('A', $rows[1]['title']);
    }

    public function testParseCsvEscapedQuoteAndEmbeddedNewline(): void
    {
        $csv = "a,b\n\"line1\nline2\",\"say \"\"hi\"\"\"\n";
        $rows = Explorer::parseCsv($csv);
        $this->assertSame("line1\nline2", $rows[0]['a']);
        $this->assertSame('say "hi"', $rows[0]['b']);
    }

    public function testParseCsvCapsRows(): void
    {
        $csv = "n\n" . implode("\n", range(1, 50));
        $rows = Explorer::parseCsv($csv, 10);
        $this->assertCount(10, $rows);
        $this->assertSame('10', $rows[9]['n']);
    }

    public function testParseCsvEmptyInput(): void
    {
        $this->assertSame([], Explorer::parseCsv(''));
        $this->assertSame([], Explorer::parseCsv("a,b\n"));
        $this->assertSame([], Explorer::parseCsv("\n\n"));
    }
}
