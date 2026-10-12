<?php

namespace Hiyori\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RateLimiter
{
    private array $buckets = [];

    private array $circuitBreakers = [];

    private LoggerInterface $logger;

    public function __construct(
        private int $maxRequests = 100,
        private int $timeWindow = 60,
        private float $circuitBreakerThreshold = 0.5,
        private int $circuitBreakerTimeout = 60,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new \Monolog\Logger('ratelimiter');
    }

    public function acquire(string $key, int $tokens = 1): bool
    {
        $now = time();

        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'tokens' => $this->maxRequests,
                'lastRefill' => $now,
            ];
        }

        $bucket = &$this->buckets[$key];

        $elapsed = $now - $bucket['lastRefill'];
        if ($elapsed > 0) {
            $refill = ($elapsed / $this->timeWindow) * $this->maxRequests;
            $bucket['tokens'] = min($this->maxRequests, $bucket['tokens'] + $refill);
            $bucket['lastRefill'] = $now;
        }

        if ($this->isCircuitOpen($key)) {
            $this->logger->warning("Circuit breaker open for {$key}");

            return false;
        }

        if ($bucket['tokens'] >= $tokens) {
            $bucket['tokens'] -= $tokens;

            return true;
        }

        $this->logger->warning("Rate limit exceeded for {$key}");

        return false;
    }

    public function waitForToken(string $key, int $tokens = 1, int $maxWait = 300): bool
    {
        $start = time();

        while (time() - $start < $maxWait) {
            if ($this->acquire($key, $tokens)) {
                return true;
            }

            usleep(100000);
        }

        return false;
    }

    public function recordSuccess(string $key): void
    {
        if (isset($this->circuitBreakers[$key])) {
            $this->circuitBreakers[$key]['failures'] = 0;
            $this->circuitBreakers[$key]['state'] = 'closed';
        }
    }

    public function recordFailure(string $key): void
    {
        if (!isset($this->circuitBreakers[$key])) {
            $this->circuitBreakers[$key] = [
                'failures' => 0,
                'state' => 'closed',
                'openedAt' => 0,
            ];
        }

        $cb = &$this->circuitBreakers[$key];
        $cb['failures']++;

        $totalRequests = $cb['failures'] + max(1, $this->maxRequests - ($this->buckets[$key]['tokens'] ?? $this->maxRequests));
        $failureRate = $cb['failures'] / $totalRequests;

        if ($failureRate >= $this->circuitBreakerThreshold && $cb['state'] === 'closed') {
            $cb['state'] = 'open';
            $cb['openedAt'] = time();
            $this->logger->error("Circuit breaker OPENED for {$key} (failure rate: " . round($failureRate * 100, 1) . '%)');
        }
    }

    private function isCircuitOpen(string $key): bool
    {
        if (!isset($this->circuitBreakers[$key])) {
            return false;
        }

        $cb = $this->circuitBreakers[$key];

        if ($cb['state'] === 'closed') {
            return false;
        }

        if ($cb['state'] === 'open' && (time() - $cb['openedAt']) >= $this->circuitBreakerTimeout) {
            $this->circuitBreakers[$key]['state'] = 'half-open';
            $this->logger->info("Circuit breaker HALF-OPEN for {$key}");

            return false;
        }

        return $cb['state'] === 'open';
    }

    public function executeWithRetry(
        callable $operation,
        string $key,
        int $maxRetries = 3,
        int $baseDelayMs = 1000,
        float $backoffMultiplier = 2.0,
        int $maxDelayMs = 30000
    ): mixed {
        $lastException = null;
        $delay = $baseDelayMs;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if (!$this->waitForToken($key)) {
                throw new \RuntimeException("Rate limit timeout for {$key}");
            }

            try {
                $result = $operation();
                $this->recordSuccess($key);

                return $result;
            } catch (TransportExceptionInterface $e) {
                $lastException = $e;
                $this->recordFailure($key);
                $this->logger->warning("Transport error for {$key} (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
            } catch (ServerExceptionInterface $e) {
                $lastException = $e;
                $this->recordFailure($key);
                $this->logger->warning("Server error for {$key} (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
            } catch (ClientExceptionInterface $e) {
                if ($e->getCode() === 429) {
                    $lastException = $e;
                    $this->recordFailure($key);
                    $delay = min($delay * $backoffMultiplier, $maxDelayMs);
                    $this->logger->warning("Rate limited (429) for {$key} (attempt {$attempt}/{$maxRetries}), waiting {$delay}ms");
                } else {
                    throw $e;
                }
            } catch (RedirectionExceptionInterface $e) {
                throw $e;
            } catch (DecodingExceptionInterface $e) {
                throw $e;
            } catch (\Exception $e) {
                $lastException = $e;
                $this->recordFailure($key);
                $this->logger->warning("Unknown error for {$key} (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
            }

            if ($attempt < $maxRetries) {
                usleep($delay * 1000);
                $delay = min((int)($delay * $backoffMultiplier), $maxDelayMs);
            }
        }

        throw $lastException ?? new \RuntimeException("Max retries exceeded for {$key}");
    }

    public function getStats(string $key): array
    {
        return [
            'tokens_available' => $this->buckets[$key]['tokens'] ?? $this->maxRequests,
            'max_tokens' => $this->maxRequests,
            'circuit_state' => $this->circuitBreakers[$key]['state'] ?? 'closed',
            'failures' => $this->circuitBreakers[$key]['failures'] ?? 0,
        ];
    }
}
