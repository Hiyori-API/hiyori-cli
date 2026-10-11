<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Helper;
use PHPUnit\Framework\TestCase;

final class HelperTest extends TestCase
{
    public function testCleanseTrimsWhitespace(): void
    {
        $this->assertSame('clean', Helper::cleanse('  clean  '));
        $this->assertSame('clean', Helper::cleanse("\tclean\n"));
        $this->assertSame('', Helper::cleanse('   '));
    }

    public function testPrepareAsIdentifierLowercasesAndTrims(): void
    {
        $this->assertSame('myanimelist', Helper::prepareAsIdentifer('  MyAnimeList  '));
        $this->assertSame('kitsu', Helper::prepareAsIdentifer('KITSU'));
    }

    public function testToArrayValidJson(): void
    {
        $json = '{"key": "value", "num": 123}';
        $result = Helper::toArray($json);
        $this->assertSame(['key' => 'value', 'num' => 123], $result);
    }

    public function testToArrayInvalidJsonThrows(): void
    {
        $this->expectException(\TypeError::class);
        Helper::toArray('not valid json');
    }

    public function testConvertMongoResultToArrayHandlesBSONDocument(): void
    {
        $doc = new \MongoDB\Model\BSONDocument(['key' => 'value', 'num' => 42]);
        $result = Helper::convertMongoResultToArray($doc);
        // Now preserves numeric types
        $this->assertSame(['key' => 'value', 'num' => 42], $result);
    }

    public function testConvertMongoResultToArrayHandlesBSONArray(): void
    {
        $arr = new \MongoDB\Model\BSONArray(['a', 'b', 'c']);
        $result = Helper::convertMongoResultToArray($arr);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testConvertMongoResultToArrayHandlesObjectId(): void
    {
        $oid = new \MongoDB\BSON\ObjectId('507f1f77bcf86cd799439011');
        $result = Helper::convertMongoResultToArray($oid);
        $this->assertSame('507f1f77bcf86cd799439011', $result);
    }

    public function testConvertMongoResultToArrayNested(): void
    {
        $doc = new \MongoDB\Model\BSONDocument([
            'id' => new \MongoDB\BSON\ObjectId('507f1f77bcf86cd799439011'),
            'items' => new \MongoDB\Model\BSONArray([1, 2, 3]),
            'nested' => new \MongoDB\Model\BSONDocument(['foo' => 'bar']),
        ]);
        $result = Helper::convertMongoResultToArray($doc);
        // Now preserves numeric types in arrays
        $this->assertSame([
            'id' => '507f1f77bcf86cd799439011',
            'items' => [1, 2, 3],
            'nested' => ['foo' => 'bar'],
        ], $result);
    }

    public function testConvertMongoResultToArrayPassesThroughPrimitives(): void
    {
        $this->assertSame('string', Helper::convertMongoResultToArray('string'));
        $this->assertSame(123, Helper::convertMongoResultToArray(123));
        $this->assertSame(3.14, Helper::convertMongoResultToArray(3.14));
        $this->assertSame(true, Helper::convertMongoResultToArray(true));
        $this->assertSame(false, Helper::convertMongoResultToArray(false));
    }

    public function testConvertMongoResultToArrayNullThrows(): void
    {
        // Now returns null instead of throwing (wider return type)
        $this->assertNull(Helper::convertMongoResultToArray(null));
    }
}
