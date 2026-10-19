<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\RateLimiter;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * effectiveLimits() is the single owner of the rate-limit precedence rule:
 * RATE_LIMIT_* env vars, then a full `limits` override in ui.json.
 */
final class RateLimiterLimitsTest extends TestCase
{
    private string $stateDir = '';

    private array $env = [];

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/hiyori-rll-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0o777, true);
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    private function writeUi(array $limits): void
    {
        file_put_contents($this->stateDir . '/ui.json', json_encode(['limits' => $limits]));
    }

    private function limiter(?int $max = null, ?int $window = null): RateLimiter
    {
        return new RateLimiter($max, $window, 0.5, 60, 10, new Logger('test'), $this->stateDir);
    }

    public function testDefaultsWhenNoEnvAndNoOverride(): void
    {
        unset($_ENV['RATE_LIMIT_MAX'], $_ENV['RATE_LIMIT_WINDOW']);

        $this->assertSame(['max' => 100, 'window' => 60, 'source' => 'default'], RateLimiter::effectiveLimits($this->stateDir));
    }

    public function testEnvVarsAreUsedWhenNoOverride(): void
    {
        $_ENV['RATE_LIMIT_MAX'] = '7';
        $_ENV['RATE_LIMIT_WINDOW'] = '30';

        $this->assertSame(['max' => 7, 'window' => 30, 'source' => 'env'], RateLimiter::effectiveLimits($this->stateDir));
    }

    public function testFullOverrideWinsOverEnv(): void
    {
        $_ENV['RATE_LIMIT_MAX'] = '7';
        $_ENV['RATE_LIMIT_WINDOW'] = '30';
        $this->writeUi(['max' => 5, 'window' => 15]);

        $this->assertSame(['max' => 5, 'window' => 15, 'source' => 'override'], RateLimiter::effectiveLimits($this->stateDir));
    }

    public function testPartialOverrideIsIgnored(): void
    {
        unset($_ENV['RATE_LIMIT_MAX'], $_ENV['RATE_LIMIT_WINDOW']);
        $this->writeUi(['max' => 5]);

        $this->assertSame(['max' => 100, 'window' => 60, 'source' => 'default'], RateLimiter::effectiveLimits($this->stateDir));
    }

    public function testNonPositiveOverrideIsIgnored(): void
    {
        unset($_ENV['RATE_LIMIT_MAX'], $_ENV['RATE_LIMIT_WINDOW']);
        $this->writeUi(['max' => 0, 'window' => 0]);

        $this->assertSame(['max' => 100, 'window' => 60, 'source' => 'default'], RateLimiter::effectiveLimits($this->stateDir));
    }

    public function testNegativeOverrideIsIgnored(): void
    {
        unset($_ENV['RATE_LIMIT_MAX'], $_ENV['RATE_LIMIT_WINDOW']);
        $this->writeUi(['max' => -1, 'window' => 10]);

        $this->assertSame(['max' => 100, 'window' => 60, 'source' => 'default'], RateLimiter::effectiveLimits($this->stateDir));
    }

    public function testConstructorAppliesFullOverride(): void
    {
        $this->writeUi(['max' => 5, 'window' => 15]);

        $this->assertSame(5, $this->limiter()->getStats('x')['max_tokens']);
    }

    public function testConstructorKeepsExplicitArgsWithoutOverride(): void
    {
        $this->assertSame(3, $this->limiter(3, 60)->getStats('x')['max_tokens']);
    }

    public function testConstructorKeepsExplicitArgsWhenOverrideIsPartial(): void
    {
        $this->writeUi(['max' => 5]);

        $this->assertSame(3, $this->limiter(3, 60)->getStats('x')['max_tokens']);
    }
}
