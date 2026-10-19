<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * Derives the dashboard's alerts from a health check.
 *
 * Delivery is NOT this service's job: {@see AlertNotifier} owns the
 * webhook/telegram/e-mail channels and the once-per-hour throttle. This
 * service only decides *what* is wrong — which is why the two halves were
 * split in the first place.
 *
 * The health check is passed in rather than probed here, so /api/status runs
 * the external probes once and shares the result with the alert derivation.
 */
final class AlertEngine
{
    private Telemetry $telemetry;

    public function __construct(Telemetry $telemetry)
    {
        $this->telemetry = $telemetry;
    }

    /**
     * The alerts implied by a health check result.
     *
     * @param array<string, mixed> $health Result of HealthCheck::check()
     *
     * @return list<array{level: string, type: string, source: string, msg: string}>
     */
    public function derive(array $health): array
    {
        $out = [];
        $h = $health;
        $rl = $h['checks']['rate_limiter']['limiters'] ?? [];
        foreach ($rl as $src => $s) {
            if (($s['circuit_state'] ?? 'closed') === 'open') {
                $out[] = ['level' => 'critical', 'type' => 'circuit_open', 'source' => $src, 'msg' => "Circuit breaker open for $src"];
            }
            if ((int) ($s['tokens_available'] ?? 999) < 5) {
                $out[] = ['level' => 'warn', 'type' => 'low_tokens', 'source' => $src, 'msg' => "Rate limit nearly exhausted for $src"];
            }
        }

        // "Unreachable" now means the probe got no HTTP response at all (transport
        // error / 5s timeout). 429 rate-limits are reachable and ignored here.
        $apis = $h['checks']['external_apis'] ?? [];
        $reachable = $apis['all_reachable'] ?? ($apis['healthy'] ?? true);
        if (!$reachable && $this->outageDebounce(true)) {
            $out[] = ['level' => 'critical', 'type' => 'api_down', 'source' => '*', 'msg' => 'One or more external APIs unreachable'];
        } elseif ($reachable) {
            $this->outageDebounce(false);
        }

        // Degraded (429 etc.) feedback — soft warning, only when sustained.
        if (($apis['healthy'] ?? true) === false && ($apis['all_reachable'] ?? true)) {
            $hits = [];
            foreach ($apis['endpoints'] ?? [] as $name => $ep) {
                if (($ep['healthy'] ?? true) === false) {
                    $hits[] = $name . (($ep['note'] ?? null) === 'rate_limited' ? ' (429)' : '');
                }
            }
            if ($hits !== []) {
                $st = $this->telemetry->read('api-outage');
                $dec = (int) ($st['degraded_count'] ?? 0) + 1;
                $this->telemetry->update('api-outage', array_merge($st, ['degraded_count' => $dec]));
                if ($dec >= 3) {
                    $out[] = ['level' => 'warn', 'type' => 'api_degraded', 'source' => '*', 'msg' => 'External APIs degraded: ' . implode(', ', $hits)];
                }
            }
        }

        // Multi-channel delivery (webhook / telegram / e-mail), throttled once per
        // hour per channel+alert identity in the alerts-sent telemetry store.
        if ($out !== []) {
            AlertNotifier::deliver($out, $this->telemetry);
        }

        return $out;
    }

    /**
     * Whether an outage should be raised now.
     *
     * Only raise the "unreachable" alert after a couple of consecutive failed
     * probes and only while the outage is recent — a single rate-limit blip
     * (429) or one poll timeout must not flash the banner.
     */
    public function outageDebounce(bool $unreachable): bool
    {
        $state = $this->telemetry->read('api-outage');
        $count = (int) ($state['count'] ?? 0);
        if ($unreachable) {
            ++$count;
            $this->telemetry->update('api-outage', ['count' => $count, 'last_failed' => time()]);
        } else {
            $prev = (int) ($state['degraded_count'] ?? 0);
            $deg = $prev >= 3 ? 0 : $prev;
            $this->telemetry->update('api-outage', [
                'count' => 0,
                'last_failed' => null,
                'degraded_count' => max(0, $count === 0 ? $deg : $deg),
            ]);
        }

        return $count >= 2 && (time() - (int) ($state['last_failed'] ?? 0)) < 120;
    }
}
