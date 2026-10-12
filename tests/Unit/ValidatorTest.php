<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    private function validEntry(): array
    {
        return [
            'title' => 'Cowboy Bebop',
            'synonyms' => ['Cowboy Bebop', 'カウボーイビバップ'],
            'type' => 'tv',
            'episodes' => 26,
            'status' => 'finished',
            'season' => 'spring',
            'year' => 1998,
            'images' => ['https://cdn.example.com/a.jpg'],
            'reference_ids' => ['al' => '1', 'mal' => '1'],
            'references' => ['https://anilist.co/anime/1', 'https://myanimelist.net/anime/1'],
            'tags' => ['action', 'sci-fi'],
        ];
    }

    public function testValidEntryHasNoErrors(): void
    {
        $this->assertSame([], $this->validator->validate($this->validEntry()));
    }

    public function testMissingTitleFails(): void
    {
        $entry = $this->validEntry();
        unset($entry['title']);
        $errors = $this->validator->validate($entry);
        $this->assertArrayHasKey('title', $errors);
    }

    public function testEmptyTitleFails(): void
    {
        $entry = $this->validEntry();
        $entry['title'] = '   ';
        $this->assertArrayHasKey('title', $this->validator->validate($entry));
    }

    public function testInvalidTypeFails(): void
    {
        $entry = $this->validEntry();
        $entry['type'] = 'not-a-type';
        $this->assertArrayHasKey('type', $this->validator->validate($entry));
    }

    public function testValidMangaTypePasses(): void
    {
        $entry = $this->validEntry();
        $entry['type'] = 'manga';
        $this->assertSame([], $this->validator->validate($entry));
    }

    public function testInvalidStatusFails(): void
    {
        $entry = $this->validEntry();
        $entry['status'] = 'not-a-status';
        $this->assertArrayHasKey('status', $this->validator->validate($entry));
    }

    public function testInvalidYearFails(): void
    {
        $entry = $this->validEntry();
        $entry['year'] = 1500;
        $this->assertArrayHasKey('year', $this->validator->validate($entry));
    }

    public function testNegativeEpisodesFails(): void
    {
        $entry = $this->validEntry();
        $entry['episodes'] = -5;
        $this->assertArrayHasKey('episodes', $this->validator->validate($entry));
    }

    public function testMissingReferencesFails(): void
    {
        $entry = $this->validEntry();
        unset($entry['references']);
        $this->assertArrayHasKey('references', $this->validator->validate($entry));
    }

    public function testInvalidReferenceUrlFails(): void
    {
        $entry = $this->validEntry();
        $entry['references'] = ['not-a-url'];
        $this->assertArrayHasKey('references', $this->validator->validate($entry));
    }

    public function testValidateOrFailThrowsWithMessage(): void
    {
        $entry = $this->validEntry();
        $entry['title'] = '';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation failed');
        $this->validator->validateOrFail($entry);
    }

    public function testValidatePayloadRequiresTitleAndId(): void
    {
        $errors = $this->validator->validatePayload([]);
        $this->assertArrayHasKey('title', $errors);
        $this->assertArrayHasKey('id', $errors);

        $this->assertSame([], $this->validator->validatePayload(['title' => 'X', 'id' => 1]));
    }
}
