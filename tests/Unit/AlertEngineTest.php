<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\AlertEngine;
use Hiyori\Service\Telemetry;
use PHPUnit\Framework\TestCase;

/**
 * The alert derivation: what the dashboard says is wrong.
 *
 * Delivery is AlertNotifier's business; this is about which alerts exist and
 * when an outage is allowed to raise the banner.
 */
final class AlertEngineTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hiyori-alerts-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        // No delivery channels: the engine hands its alerts to the notifier,
        // and a configured webhook would turn a unit test into an HTTP call.
        foreach (['WEBHOOK_URL', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'SMTP_HOST', 'ALERT_EMAIL_TO'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function engine(): AlertEngine
    {
        return new AlertEngine(new Telemetry($this->dir));
    }

    /**
     * @param array<string, mixed> $checks
     *
     * @return array<string, mixed>
     */
    private function health(array $checks): array
    {
        return ['status' => 'healthy', 'timestamp' => date('c'), 'checks' => $checks];
    }

    public function testAnOpenCircuitIsCritical(): void
    {
        $alerts = $this->engine()->derive($this->health([
            'rate_limiter' => ['limiters' => ['anilist' => ['circuit_state' => 'open', 'tokens_available' => 100]]],
        ]));

        $this->assertCount(1, $alerts);
        $this->assertSame('critical', $alerts[0]['level']);
        $this->assertSame('circuit_open', $alerts[0]['type']);
        $this->assertSame('anilist', $alerts[0]['source']);
    }

    public function testNearlyExhaustedTokensAreAWarning(): void
    {
        $alerts = $this->engine()->derive($this->health([
            'rate_limiter' => ['limiters' => ['kitsu' => ['circuit_state' => 'closed', 'tokens_available' => 2]]],
        ]));

        $this->assertCount(1, $alerts);
        $this->assertSame('warn', $alerts[0]['level']);
        $this->assertSame('low_tokens', $alerts[0]['type']);
    }

    public function testAHealthySystemRaisesNothing(): void
    {
        $alerts = $this->engine()->derive($this->health([
            'rate_limiter' => ['limiters' => ['anilist' => ['circuit_state' => 'closed', 'tokens_available' => 100]]],
            'external_apis' => ['all_reachable' => true, 'healthy' => true, 'endpoints' => []],
        ]));

        $this->assertSame([], $alerts);
    }

    public function testAnOutageIsOnlyRaisedAfterTwoConsecutiveFailures(): void
    {
        $engine = $this->engine();
        $down = $this->health([
            'external_apis' => ['all_reachable' => false, 'healthy' => false, 'endpoints' => []],
        ]);

        $this->assertSame([], $engine->derive($down), 'the first failed probe must not flash the banner');

        $alerts = $engine->derive($down);
        $this->assertCount(1, $alerts);
        $this->assertSame('api_down', $alerts[0]['type']);
        $this->assertSame('critical', $alerts[0]['level']);
    }

    public function testAReachableApiClearsTheOutageCounter(): void
    {
        $engine = $this->engine();
        $down = $this->health([
            'external_apis' => ['all_reachable' => false, 'healthy' => false, 'endpoints' => []],
        ]);
        $up = $this->health([
            'external_apis' => ['all_reachable' => true, 'healthy' => true, 'endpoints' => []],
        ]);

        $engine->derive($down);
        $engine->derive($up);
        $state = (new Telemetry($this->dir))->read('api-outage');

        $this->assertSame(0, $state['count']);
        $this->assertNull($state['last_failed']);
    }

    public function testDegradationIsOnlyWarnedAboutWhenItIsSustained(): void
    {
        $engine = $this->engine();
        $degraded = $this->health([
            'external_apis' => [
                'all_reachable' => true,
                'healthy' => false,
                'endpoints' => [
                    'jikan' => ['healthy' => false, 'note' => 'rate_limited'],
                    'kitsu' => ['healthy' => true],
                ],
            ],
        ]);

        $this->assertSame([], $engine->derive($degraded));
        $this->assertSame([], $engine->derive($degraded));

        $alerts = $engine->derive($degraded);
        $this->assertCount(1, $alerts);
        $this->assertSame('api_degraded', $alerts[0]['type']);
        $this->assertSame('warn', $alerts[0]['level']);
        $this->assertStringContainsString('jikan (429)', $alerts[0]['msg']);
    }
}
