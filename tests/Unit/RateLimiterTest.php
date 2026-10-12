<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testAcquireAllowsUpToMaxRequestsPerWindow(): void
    {
        $limiter = new RateLimiter(3, 60);
        $this->assertTrue($limiter->acquire('test'));
        $this->assertTrue($limiter->acquire('test'));
        $this->assertTrue($limiter->acquire('test'));
        $this->assertFalse($limiter->acquire('test'), 'Should be exhausted after max tokens');
    }

    public function testTokensAreIndependentPerKey(): void
    {
        $limiter = new RateLimiter(1, 60);
        $this->assertTrue($limiter->acquire('a'));
        $this->assertFalse($limiter->acquire('a'));
        $this->assertTrue($limiter->acquire('b'), 'Different keys have separate buckets');
    }

    public function testWaitForTokenReturnsWithinMaxWait(): void
    {
        $limiter = new RateLimiter(1, 1);
        $this->assertTrue($limiter->waitForToken('key', 1, 2), 'Should eventually acquire after refill');
    }

    public function testRecordFailureOpensCircuitBreaker(): void
    {
        $limiter = new RateLimiter(100, 60, 0.5, 60);
        for ($i = 0; $i < 60; $i++) {
            $limiter->recordFailure('api');
        }
        $stats = $limiter->getStats('api');
        $this->assertSame('open', $stats['circuit_state'], 'High failure rate should open circuit');
    }

    public function testRecordSuccessResetsCircuitBreaker(): void
    {
        $limiter = new RateLimiter(100, 60, 0.5, 60);
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
        $limiter = new RateLimiter(100, 60);
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
        $limiter = new RateLimiter(100, 60);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transient');
        $limiter->executeWithRetry(function () {
            throw new \RuntimeException('transient');
        }, 'api', 2, 1);
    }
}
