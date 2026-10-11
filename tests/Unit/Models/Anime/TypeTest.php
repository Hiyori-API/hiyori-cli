<?php

namespace Hiyori\Tests\Unit\Models\Anime;

use Hiyori\Models\Anime\Type;
use PHPUnit\Framework\TestCase;

final class TypeTest extends TestCase
{
    public function testFromStringTvVariants(): void
    {
        $this->assertSame(Type::TV, Type::fromString('tv'));
        $this->assertSame(Type::TV, Type::fromString('tv_short'));
    }

    public function testFromStringMovie(): void
    {
        $this->assertSame(Type::MOVIE, Type::fromString('movie'));
    }

    public function testFromStringOva(): void
    {
        $this->assertSame(Type::OVA, Type::fromString('ova'));
    }

    public function testFromStringOna(): void
    {
        $this->assertSame(Type::ONA, Type::fromString('ona'));
    }

    public function testFromStringSpecial(): void
    {
        $this->assertSame(Type::SPECIAL, Type::fromString('special'));
    }

    public function testFromStringMusic(): void
    {
        $this->assertSame(Type::MUSIC, Type::fromString('music'));
    }

    public function testFromStringUnknownThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('TYPE: Unexpected match value');
        Type::fromString('unknown-type');
    }

    public function testEnumValues(): void
    {
        $this->assertSame('tv', Type::TV->value);
        $this->assertSame('movie', Type::MOVIE->value);
        $this->assertSame('ova', Type::OVA->value);
        $this->assertSame('ona', Type::ONA->value);
        $this->assertSame('special', Type::SPECIAL->value);
        $this->assertSame('music;', Type::MUSIC->value);
    }
}
