<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\RateLimiter;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $stateDir = '';

    protected function setUp(): void
    {
        // A limiter reads ui.json and now persists breaker state, so every test
        // gets its own directory rather than touching storage/state.
        $this->stateDir = sys_get_temp_dir() . '/hiyori-rl-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    private function limiter(int $max, int $window, float $threshold = 0.5, int $timeout = 60): RateLimiter
    {
        return new RateLimiter($max, $window, $threshold, $timeout, 10, new Logger('test'), $this->stateDir);
    }

    public function testAcquireAllowsUpToMaxRequestsPerWindow(): void
    {
        $limiter = $this->limiter(3, 60);
        $this->assertTrue($limiter->acquire('test'));
        $this->assertTrue($limiter->acquire('test'));
        $this->assertTrue($limiter->acquire('test'));
        $this->assertFalse($limiter->acquire('test'), 'Should be exhausted after max tokens');
    }

    public function testTokensAreIndependentPerKey(): void
    {
        $limiter = $this->limiter(1, 60);
        $this->assertTrue($limiter->acquire('a'));
        $this->assertFalse($limiter->acquire('a'));
        $this->assertTrue($limiter->acquire('b'), 'Different keys have separate buckets');
    }

    public function testWaitForTokenReturnsWithinMaxWait(): void
    {
        $limiter = $this->limiter(1, 1);
        $this->assertTrue($limiter->waitForToken('key', 1, 2), 'Should eventually acquire after refill');
    }

    public function testRecordFailureOpensCircuitBreaker(): void
    {
        $limiter = $this->limiter(100, 60);
        for ($i = 0; $i < 60; $i++) {
            $limiter->recordFailure('api');
        }
        $stats = $limiter->getStats('api');
        $this->assertSame('open', $stats['circuit_state'], 'High failure rate should open circuit');
    }

    public function testRecordSuccessResetsCircuitBreaker(): void
    {
        $limiter = $this->limiter(100, 60);
        for ($i = 0; $i < 60; $i++) {
            $limiter->recordFailure('api');
        }
        $this->assertSame('open', $limiter->getStats('api')['circuit_state']);
        $limiter->recordSuccess('api');
        $stats = $limiter->getStats('api');
        $this->assertSame('closed', $stats['circuit_state']);
        $this->assertSame(0, $stats['failures']);
    }

    public function testExecuteWithRetrySucceeds(): void
    {
        $limiter = $this->limiter(100, 60);
        $attempts = 0;
        $result = $limiter->executeWithRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('transient');
            }

            return 'success';
        }, 'api', 3, 1);

        $this->assertSame('success', $result);
        $this->assertSame(3, $attempts);
    }

    public function testExecuteWithRetryThrowsAfterMaxRetries(): void
    {
        $limiter = $this->limiter(100, 60);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transient');
        $limiter->executeWithRetry(function () {
            throw new \RuntimeException('transient');
        }, 'api', 2, 1);
    }

    public function testUnobservedLimiterReportsNothing(): void
    {
        // getStats() fabricates "closed / full tokens" for an unseen key; the
        // dashboard must be able to tell that apart from real state.
        $this->assertFalse($this->limiter(100, 60)->hasObserved('anilist'));
    }

    public function testOpenBreakerSurvivesIntoTheNextProcess(): void
    {
        $limiter = $this->limiter(100, 60);
        for ($i = 0; $i < 60; $i++) {
            $limiter->recordFailure('anilist');
        }
        $this->assertSame('open', $limiter->getStats('anilist')['circuit_state']);

        // The dashboard's HealthCheck gets a fresh limiter on the next request.
        $next = $this->limiter(100, 60);
        $this->assertTrue($next->hasObserved('anilist'));
        $this->assertSame('open', $next->getStats('anilist')['circuit_state']);
    }

    public function testRecoveryIsPersistedToo(): void
    {
        $limiter = $this->limiter(100, 60);
        for ($i = 0; $i < 60; $i++) {
            $limiter->recordFailure('anilist');
        }
        $limiter->recordSuccess('anilist');

        $this->assertSame('closed', $this->limiter(100, 60)->getStats('anilist')['circuit_state']);
    }
}
