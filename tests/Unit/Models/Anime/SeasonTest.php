<?php

namespace Hiyori\Tests\Unit\Models\Anime;

use Hiyori\Models\Anime\Season;
use PHPUnit\Framework\TestCase;

final class SeasonTest extends TestCase
{
    public function testFromStringDirect(): void
    {
        $this->assertSame(Season::WINTER, Season::fromString('winter'));
        $this->assertSame(Season::SUMMER, Season::fromString('summer'));
        $this->assertSame(Season::SPRING, Season::fromString('spring'));
        $this->assertSame(Season::FALL, Season::fromString('fall'));
        $this->assertSame(Season::FALL, Season::fromString('autumn'));
    }

    public function testFromStringUnknownThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SEASON: Unexpected match value');
        Season::fromString('unknown-season');
    }

    public function testFromMonthWinter(): void
    {
        foreach ([1, 2, 3] as $month) {
            $this->assertSame(Season::WINTER, Season::fromMonth((string)$month), "Month $month should be Winter");
        }
    }

    public function testFromMonthSpring(): void
    {
        foreach ([4, 5, 6] as $month) {
            $this->assertSame(Season::SPRING, Season::fromMonth((string)$month), "Month $month should be Spring");
        }
    }

    public function testFromMonthSummer(): void
    {
        foreach ([7, 8, 9] as $month) {
            $this->assertSame(Season::SUMMER, Season::fromMonth((string)$month), "Month $month should be Summer");
        }
    }

    public function testFromMonthFall(): void
    {
        foreach ([10, 11, 12] as $month) {
            $this->assertSame(Season::FALL, Season::fromMonth((string)$month), "Month $month should be Fall");
        }
    }

    public function testFromMonthInvalidThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Could not generate seasonal string');
        Season::fromMonth('13');
    }

    public function testEnumValues(): void
    {
        $this->assertSame('winter', Season::WINTER->value);
        $this->assertSame('summer', Season::SUMMER->value);
        $this->assertSame('spring', Season::SPRING->value);
        $this->assertSame('fall', Season::FALL->value);
    }
}
