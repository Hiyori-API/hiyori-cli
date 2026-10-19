<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hiyori-env-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/.*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    private function env(string $contents = ''): EnvFile
    {
        if ($contents !== '') {
            file_put_contents($this->dir . '/.env', $contents);
        }

        return new EnvFile($this->dir . '/.env', $this->dir . '/ui.json');
    }

    public function testAllParsesKeysAndSkipsCommentsBlankAndJunk(): void
    {
        $env = $this->env("# a comment\n\nAPP_ENV=production\nWEBHOOK_URL=https://example.test/hook\nNOEQUALS\n  SPACED = 1\n");

        $this->assertSame([
            'APP_ENV' => 'production',
            'WEBHOOK_URL' => 'https://example.test/hook',
            'SPACED' => ' 1',
        ], $env->all());
    }

    public function testAllAndRawAreEmptyWithoutFile(): void
    {
        $env = new EnvFile($this->dir . '/missing.env', $this->dir . '/ui.json');

        $this->assertSame([], $env->all());
        $this->assertSame('', $env->raw());
    }

    public function testRawReturnsFileVerbatim(): void
    {
        $contents = "APP_ENV=test\n# keep me\n";
        $env = $this->env($contents);

        $this->assertSame($contents, $env->raw());
    }

    public function testSetUpdatesInPlaceAndPreservesCommentsAndBlankLines(): void
    {
        $env = $this->env("# header\n\nAPP_ENV=production\n# tail\n");

        $this->assertTrue($env->set('APP_ENV', 'development'));

        $this->assertSame("# header\n\nAPP_ENV=development\n# tail\n", $env->raw());
        $this->assertSame('development', $env->all()['APP_ENV']);
        $this->assertFileExists($this->dir . '/.env.bak');
        $this->assertStringContainsString('APP_ENV=production', (string) file_get_contents($this->dir . '/.env.bak'));
    }

    public function testSetAppendsNewKey(): void
    {
        $env = $this->env("APP_ENV=production\n");

        $this->assertTrue($env->set('DASHBOARD_TOKEN', 'abc123'));

        $this->assertSame("APP_ENV=production\nDASHBOARD_TOKEN=abc123\n", $env->raw());
    }

    public function testSetReturnsFalseWhenFileMissing(): void
    {
        $env = new EnvFile($this->dir . '/missing.env', $this->dir . '/ui.json');

        $this->assertFalse($env->set('APP_ENV', 'test'));
    }

    public function testSetRejectsCrlfInjection(): void
    {
        $env = $this->env("APP_ENV=production\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('value must be a single line');
        $env->set('APP_ENV', "safe\nINJECTED=1");
    }

    public function testSetRejectsCarriageReturnInjection(): void
    {
        $env = $this->env("APP_ENV=production\n");

        $this->expectException(\InvalidArgumentException::class);
        $env->set('APP_ENV', "safe\rINJECTED=1");
    }

    public function testRemoveDropsKeyAndKeepsComments(): void
    {
        $env = $this->env("# header\nAPP_ENV=production\n# tail\nWEBHOOK_URL=x\n");

        $env->remove('APP_ENV');

        $this->assertSame("# header\n# tail\nWEBHOOK_URL=x\n", $env->raw());
        $this->assertArrayNotHasKey('APP_ENV', $env->all());
        $this->assertFileExists($this->dir . '/.env.bak');
    }

    public function testRemoveOnMissingFileIsANoop(): void
    {
        $env = new EnvFile($this->dir . '/missing.env', $this->dir . '/ui.json');

        $env->remove('APP_ENV');

        $this->assertFileDoesNotExist($this->dir . '/missing.env');
    }

    public function testReplaceWritesTrimmedSingleTrailingNewline(): void
    {
        $env = $this->env("APP_ENV=production\n");

        $env->replace("APP_ENV=test\n\n\n");

        $this->assertSame("APP_ENV=test\n", $env->raw());
    }

    public function testReplaceCreatesFileAndDirectory(): void
    {
        $nested = $this->dir . '/nested';
        $env = new EnvFile($nested . '/.env', $this->dir . '/ui.json');

        $env->replace('APP_ENV=test');

        $this->assertSame("APP_ENV=test\n", (string) file_get_contents($nested . '/.env'));
    }

    public function testMaskRevealsNothingForShortValues(): void
    {
        $env = $this->env();

        $this->assertSame('', $env->mask(''));
        $this->assertSame('••••', $env->mask('12345'));
        $this->assertSame('••••', $env->mask('12345678'));
        $this->assertSame('••••', $env->mask('12345678901'));
    }

    public function testMaskKeepsPrefixForLongValues(): void
    {
        $env = $this->env();

        $this->assertSame('abcd••••(12 chars)', $env->mask('abcdefghijkl'));
    }

    public function testIsSensitiveKeyMatchesBuiltInPatterns(): void
    {
        $env = $this->env();

        $this->assertTrue($env->isSensitiveKey('DASHBOARD_TOKEN'));
        $this->assertTrue($env->isSensitiveKey('CLIENT_SECRET'));
        $this->assertTrue($env->isSensitiveKey('db_password'));
        $this->assertFalse($env->isSensitiveKey('APP_ENV'));
    }

    public function testIsSensitiveKeyHonoursConfiguredPatterns(): void
    {
        file_put_contents($this->dir . '/ui.json', json_encode(['env_sensitive_patterns' => ['/^HOOK_/']]));
        $env = new EnvFile($this->dir . '/.env', $this->dir . '/ui.json');

        $this->assertTrue($env->isSensitiveKey('HOOK_URL'));
        $this->assertFalse($env->isSensitiveKey('APP_ENV'));
    }

    public function testSensitiveKeysListsOnlySensitiveNames(): void
    {
        $env = $this->env("APP_ENV=production\nDASHBOARD_TOKEN=abc123\nWEBHOOK_URL=x\n");

        $this->assertSame(['DASHBOARD_TOKEN'], $env->sensitiveKeys());
    }
}
