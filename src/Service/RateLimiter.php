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

    private string $stateDir;

    private int $maxRequests;

    private int $timeWindow;

    public function __construct(
        ?int $maxRequests = null,
        ?int $timeWindow = null,
        private float $circuitBreakerThreshold = 0.5,
        private int $circuitBreakerTimeout = 60,
        private int $circuitBreakerMinFailures = 10,
        ?LoggerInterface $logger = null,
        ?string $stateDir = null
    ) {
        $this->logger = $logger ?? self::defaultLogger();
        $this->stateDir = $stateDir ?? dirname(__DIR__, 2) . '/storage/state';

        // Limits resolve through the single precedence owner below: the
        // RATE_LIMIT_* env vars, then a dashboard override in ui.json. Passing
        // explicit values (tests) uses them as the base instead.
        $limits = self::effectiveLimits($this->stateDir, $maxRequests, $timeWindow);
        $this->maxRequests = $limits['max'];
        $this->timeWindow = $limits['window'];

        // Breaker state outlives the process: an ingest opens a breaker, then
        // exits, and the dashboard's HealthCheck runs in a different process.
        // Without this the limiter it saw had never observed anything and
        // getStats() answered "closed / full tokens" for every source.
        $stateFile = $this->stateDir . '/rate-limit-state.json';
        if (is_file($stateFile)) {
            $persisted = json_decode((string) file_get_contents($stateFile), true);
            if (is_array($persisted)) {
                $this->circuitBreakers = $persisted;
            }
        }
    }

    /**
     * Without a handler a Monolog Logger silently discards every record, so the
     * breaker transitions and retry warnings went nowhere. Write them beside the
     * main log instead.
     */
    private static function defaultLogger(): LoggerInterface
    {
        $logger = new \Monolog\Logger('ratelimiter');
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (is_dir($logDir)) {
            $logger->pushHandler(new \Monolog\Handler\RotatingFileHandler($logDir . '/ratelimiter', 5));
        }

        return $logger;
    }

    /**
     * The single owner of the rate-limit precedence rule.
     *
     * A dashboard override saved in storage/state/ui.json (`limits`) wins over
     * the RATE_LIMIT_* env vars, but only when it carries BOTH `max` and
     * `window` and both are > 0: a half-written override is ignored instead of
     * being applied field by field. `source` reports where the winner came from
     * so the dashboard can show it.
     *
     * Callers may pass base values (the constructor does, so tests and explicit
     * wiring keep their arguments) which are used unless an override applies.
     *
     * @return array{max: int, window: int, source: string}
     */
    public static function effectiveLimits(?string $stateDir = null, ?int $maxRequests = null, ?int $timeWindow = null): array
    {
        if ($maxRequests === null || $timeWindow === null) {
            $maxRequests ??= (int) ($_ENV['RATE_LIMIT_MAX'] ?? 100);
            $timeWindow ??= (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60);
            $source = isset($_ENV['RATE_LIMIT_MAX']) || isset($_ENV['RATE_LIMIT_WINDOW']) ? 'env' : 'default';
        } else {
            $source = 'argument';
        }

        $overridesFile = ($stateDir ?? dirname(__DIR__, 2) . '/storage/state') . '/ui.json';
        if (is_file($overridesFile)) {
            $ui = json_decode((string) file_get_contents($overridesFile), true);
            $override = is_array($ui) ? ($ui['limits'] ?? null) : null;
            if (is_array($override) && (int) ($override['max'] ?? 0) > 0 && (int) ($override['window'] ?? 0) > 0) {
                return [
                    'max' => (int) $override['max'],
                    'window' => (int) $override['window'],
                    'source' => 'override',
                ];
            }
        }

        return ['max' => $maxRequests, 'window' => $timeWindow, 'source' => $source];
    }

    /** Whether this limiter has observed traffic (or a persisted breaker) for a key. */
    public function hasObserved(string $key): bool
    {
        return isset($this->buckets[$key]) || isset($this->circuitBreakers[$key]);
    }

    private function persistBreakers(): void
    {
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0o755, true) && !is_dir($this->stateDir)) {
            return;
        }
        $file = $this->stateDir . '/rate-limit-state.json';
        $tmp = $file . '.tmp';
        if (false === @file_put_contents($tmp, (string) json_encode($this->circuitBreakers))) {
            return;
        }
        @rename($tmp, $file);
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
            $was = $this->circuitBreakers[$key]['state'] ?? 'closed';
            $this->circuitBreakers[$key]['failures'] = 0;
            $this->circuitBreakers[$key]['state'] = 'closed';
            if ($was !== 'closed') {
                $this->persistBreakers();
            }
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

        // Require a minimum number of failures before the breaker can trip so a
        // single transient failure (e.g. 1/2 = 50% of a small sample) doesn't
        // lock the source out for the full timeout.
        if ($cb['failures'] < $this->circuitBreakerMinFailures || $cb['state'] !== 'closed') {
            return;
        }

        $totalRequests = $cb['failures'] + max(1, $this->maxRequests - ($this->buckets[$key]['tokens'] ?? $this->maxRequests));
        $failureRate = $cb['failures'] / $totalRequests;

        if ($failureRate >= $this->circuitBreakerThreshold) {
            $cb['state'] = 'open';
            $cb['openedAt'] = time();
            $this->logger->error("Circuit breaker OPENED for {$key} (failures: " . $cb['failures'] . ', rate: ' . round($failureRate * 100, 1) . '%)');
            $this->persistBreakers();
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
            $this->persistBreakers();

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
