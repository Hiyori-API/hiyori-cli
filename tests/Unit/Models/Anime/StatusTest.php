<?php

namespace Hiyori\Tests\Unit\Models\Anime;

use Hiyori\Models\Anime\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    public function testFromStringFinishedVariants(): void
    {
        $this->assertSame(Status::FINISHED, Status::fromString('finished'));
        $this->assertSame(Status::FINISHED, Status::fromString('finished airing'));
        $this->assertSame(Status::FINISHED, Status::fromString('completed'));
    }

    public function testFromStringAiringVariants(): void
    {
        $this->assertSame(Status::AIRING, Status::fromString('airing'));
        $this->assertSame(Status::AIRING, Status::fromString('currently airing'));
        $this->assertSame(Status::AIRING, Status::fromString('releasing'));
        $this->assertSame(Status::AIRING, Status::fromString('current'));
        $this->assertSame(Status::AIRING, Status::fromString('ongoing'));
    }

    public function testFromStringToBeAiredVariants(): void
    {
        $this->assertSame(Status::TO_BE_AIRED, Status::fromString('not yet aired'));
        $this->assertSame(Status::TO_BE_AIRED, Status::fromString('to be aired'));
        $this->assertSame(Status::TO_BE_AIRED, Status::fromString('tba'));
        $this->assertSame(Status::TO_BE_AIRED, Status::fromString('not_yet_released'));
        $this->assertSame(Status::TO_BE_AIRED, Status::fromString('unreleased'));
        $this->assertSame(Status::TO_BE_AIRED, Status::fromString('upcoming'));
    }

    public function testFromStringCancelled(): void
    {
        $this->assertSame(Status::CANCELLED, Status::fromString('cancelled'));
    }

    public function testFromStringUnknownThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('STATUS: Unexpected match value unknown-status');
        Status::fromString('unknown-status');
    }

    public function testEnumValues(): void
    {
        $this->assertSame('finished', Status::FINISHED->value);
        $this->assertSame('airing', Status::AIRING->value);
        $this->assertSame('to_be_aired', Status::TO_BE_AIRED->value);
        $this->assertSame('cancelled', Status::CANCELLED->value);
    }
}
