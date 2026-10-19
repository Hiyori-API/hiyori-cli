<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * The schedule the dashboard shows.
 *
 * The schedule is written by the scheduler (`storage/state/schedule.json`)
 * and configured through the environment; the dashboard only ever reads it.
 * This service owns the precedence — stored state first, environment
 * second — and the shape the Settings page renders.
 */
final class Schedule
{
    /** Sources scheduled when nothing else says otherwise. */
    public const DEFAULT_SOURCES = 'anilist,kitsu,myanimelist';

    /** Seconds between runs when nothing else says otherwise (24 h). */
    public const DEFAULT_INTERVAL = 86400;

    private Telemetry $telemetry;

    public function __construct(Telemetry $telemetry)
    {
        $this->telemetry = $telemetry;
    }

    /**
     * The schedule as the dashboard renders it.
     *
     * @return array{sources: string, interval_hours: float|int, next_in: ?int, method: string}
     */
    public function view(): array
    {
        $s = $this->telemetry->read('schedule');
        $envSources = isset($_ENV['SCHEDULE_SOURCES']) && $_ENV['SCHEDULE_SOURCES'] !== '' ? $_ENV['SCHEDULE_SOURCES'] : self::DEFAULT_SOURCES;
        $sources = $s['sources'] ?? $envSources;
        $envInterval = isset($_ENV['SCHEDULE_INTERVAL']) && $_ENV['SCHEDULE_INTERVAL'] !== '' ? (int) $_ENV['SCHEDULE_INTERVAL'] : self::DEFAULT_INTERVAL;
        $interval = (int) ($s['interval'] ?? $envInterval);
        $last = (int) ($s['last_run'] ?? 0);

        return [
            'sources' => is_array($sources) ? implode(',', $sources) : (string) $sources,
            'interval_hours' => $interval / 3600,
            'next_in' => $last ? max(0, $last + $interval - time()) : null,
            'method' => $s['method'] ?? 'systemd-timer (see schedule --install)',
        ];
    }
}
