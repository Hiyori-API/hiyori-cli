<?php

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Publisher;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class PublisherTest extends TestCase
{
    private function publisher(): Publisher
    {
        $_ENV['MONGODB_URL'] = $_ENV['MONGODB_URL'] ?? 'mongodb://localhost:27017';

        return new Publisher(new Client($_ENV['MONGODB_URL']), 'hiyori_test_publish', sys_get_temp_dir() . '/hiyori-pub-test');
    }

    public function testSlugNormalizesTitles(): void
    {
        $p = $this->publisher();
        $ref = new \ReflectionMethod($p, 'slug');
        $this->assertSame('cowboy-bebop', $ref->invoke($p, 'Cowboy Bebop'));
        $this->assertSame('fullmetal-alchemist-brotherhood', $ref->invoke($p, 'Fullmetal Alchemist: Brotherhood!'));
        $this->assertSame('untitled', $ref->invoke($p, '###'));
    }

    public function testScalarFlattensArrays(): void
    {
        $p = $this->publisher();
        $ref = new \ReflectionMethod($p, 'scalar');
        $this->assertSame('x', $ref->invoke($p, ['x', 'y']));
        $this->assertSame('abc', $ref->invoke($p, ['$oid' => 'abc']));
        $this->assertSame('42', $ref->invoke($p, 42));
        $this->assertSame('', $ref->invoke($p, null));
    }
}
