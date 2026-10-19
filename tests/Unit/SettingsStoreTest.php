<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\SettingsStore;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's settings file (`storage/state/ui.json`).
 *
 * The store owns the file: the Settings page writes it, the rate limiter
 * reads the limit override out of it, and nobody else may parse it.
 */
final class SettingsStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hiyori-settings-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function store(): SettingsStore
    {
        return new SettingsStore($this->dir . '/ui.json');
    }

    public function testReadIsEmptyWithoutAFile(): void
    {
        $this->assertSame([], $this->store()->read());
        // The defaults are still applied: the Settings page shows them even
        // before anything has been saved.
        $this->assertTrue($this->store()->settings()['prefs']['realtime']);
    }

    public function testSettingsFillInThePrefsDefaults(): void
    {
        file_put_contents($this->dir . '/ui.json', (string) json_encode(['theme' => 'dark']));

        $settings = $this->store()->settings();

        $this->assertSame('dark', $settings['theme']);
        $this->assertTrue($settings['prefs']['realtime']);
        $this->assertSame(['_counters', '_migrations'], $settings['prefs']['hiddenCollections']);
    }

    public function testStoredPrefsWinOverTheDefaults(): void
    {
        file_put_contents($this->dir . '/ui.json', (string) json_encode([
            'prefs' => ['realtime' => false, 'hiddenCollections' => []],
        ]));

        $prefs = $this->store()->settings()['prefs'];

        $this->assertFalse($prefs['realtime']);
        $this->assertSame([], $prefs['hiddenCollections']);
    }

    public function testReadDoesNotAddTheDefaults(): void
    {
        file_put_contents($this->dir . '/ui.json', (string) json_encode(['theme' => 'dark']));

        $this->assertSame(['theme' => 'dark'], $this->store()->read());
    }

    public function testSaveWritesTheSettingsBack(): void
    {
        $store = $this->store();
        $store->save(['prefs' => ['weights' => ['al' => 0.6]]]);

        $this->assertSame(['prefs' => ['weights' => ['al' => 0.6]]], $store->read());
        $this->assertFileExists($this->dir . '/ui.json');
    }

    public function testSaveIsAtomicAndLeavesNoTemporaryFile(): void
    {
        $store = $this->store();
        $store->save(['a' => 1]);

        $this->assertSame([], glob($this->dir . '/*.tmp') ?: []);
    }

    public function testSaveCreatesTheDirectoryWhenItIsMissing(): void
    {
        $store = new SettingsStore($this->dir . '/nested/ui.json');
        $store->save(['a' => 1]);

        $this->assertSame(['a' => 1], $store->read());
    }

    public function testLimitOverrideIsTheRawStoredLimit(): void
    {
        $store = $this->store();
        $this->assertNull($store->limitOverride());

        $store->save(['limits' => ['max' => 5, 'window' => 15]]);
        $this->assertSame(['max' => 5, 'window' => 15], $store->limitOverride());
    }

    public function testLimitOverrideIsNullWhenTheLimitsAreNotAMap(): void
    {
        $store = $this->store();
        $store->save(['limits' => 'nonsense']);

        $this->assertNull($store->limitOverride());
    }
}
