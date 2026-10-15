<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/public/export-helpers.php';

final class ExportHelpersTest extends TestCase
{
    public function testFilenameAcceptsHiyoriIdPlaceholder(): void
    {
        $this->assertNull(\hiyoriExportFilenameError('{hiyori_id}-{slug}.json'));
        $this->assertNull(\hiyoriExportFilenameError('data/{hiyori_id}.json'));
        $this->assertNull(\hiyoriExportFilenameError('  {hiyori_id}.json  '));
    }

    public function testFilenameRejectsMissingPlaceholder(): void
    {
        $this->assertSame(
            'filename must not be empty',
            \hiyoriExportFilenameError(''),
        );
        $this->assertSame(
            'filename must contain the {hiyori_id} placeholder',
            \hiyoriExportFilenameError('{slug}.json'),
        );
    }

    public function testFilenameRejectsTraversal(): void
    {
        $this->assertSame(
            'filename must be a relative path without traversal',
            \hiyoriExportFilenameError('../{hiyori_id}.json'),
        );
        $this->assertSame(
            'filename must be a relative path without traversal',
            \hiyoriExportFilenameError('/{hiyori_id}.json'),
        );
        $this->assertSame(
            'filename must be a relative path without traversal',
            \hiyoriExportFilenameError('{hiyori_id}\\x.json'),
        );
    }

    public function testConfigErrorsReportsBadShapes(): void
    {
        $errors = \hiyoriExportConfigErrors([
            'profile' => 'nope',
            'entities' => 'anime',
            'fields' => ['ok', ''],
            'include' => ['tags' => 'yes'],
            'filename' => '{slug}.json',
        ], ['default', 'full']);

        $this->assertContains('profile must be one of: default, full', $errors);
        $this->assertContains('entities must be an object with anime/manga booleans', $errors);
        $this->assertContains('fields must contain only non-empty strings', $errors);
        $this->assertContains('include.tags must be a boolean or nested object', $errors);
        $this->assertContains('filename must contain the {hiyori_id} placeholder', $errors);
    }

    public function testConfigErrorsAcceptsValidShapes(): void
    {
        $errors = \hiyoriExportConfigErrors([
            'profile' => 'compact',
            'entities' => ['anime' => true, 'manga' => false],
            'fields' => ['hiyori_id', 'title'],
            'include' => ['tags' => true, 'images' => false, 'nested' => ['a' => true]],
            'filename' => '{hiyori_id}-{slug}.json',
        ], ['default', 'full', 'compact']);

        $this->assertSame([], $errors);
    }

    public function testConfigErrorsIgnoresUnknownKeys(): void
    {
        $this->assertSame([], \hiyoriExportConfigErrors(['subdir' => 'data', 'pretty' => true], ['default']));
    }

    public function testMergeDeepMergesNestedObjectsKeyWise(): void
    {
        $base = [
            'include' => ['tags' => true, 'images' => true],
            'entities' => ['anime' => true, 'manga' => true],
            'format' => 'json',
        ];
        $patch = [
            'include' => ['tags' => false],
            'entities' => ['manga' => false],
            'profile' => 'compact',
        ];

        $this->assertSame([
            'include' => ['tags' => false, 'images' => true],
            'entities' => ['anime' => true, 'manga' => false],
            'format' => 'json',
            'profile' => 'compact',
        ], \hiyoriExportMergeDeep($base, $patch));
    }

    public function testMergeDeepReplacesListsAndScalars(): void
    {
        $base = ['fields' => ['a', 'b'], 'filename' => '{hiyori_id}.json'];
        $patch = ['fields' => ['c'], 'filename' => '{hiyori_id}-{slug}.json'];

        $this->assertSame(
            ['fields' => ['c'], 'filename' => '{hiyori_id}-{slug}.json'],
            \hiyoriExportMergeDeep($base, $patch),
        );
    }

    public function testProfilesFallbackIsNonEmpty(): void
    {
        $this->assertNotEmpty(\hiyoriExportProfiles());
    }
}
