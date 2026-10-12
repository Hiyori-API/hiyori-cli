# Dashboard Roadmap (tracked)

Selected items (43) with status. Built = committed.

## Batch 1 (25 selected)
- [x] Confidence filters (per-doc `confidence` stored; **histogram** built)
- [x] Combine history
- [x] Run history (telemetry persists run summaries)
- [x] SSE live updates (/api/events)
- [x] Historical KPI store (state files; counts history client+server)
- [x] Multi-worker support (per-source locks + control files)
- [x] Write endpoints (/api/action pause/resume; /api/backup)
- [x] Area charts (rates/counts time-series)
- [x] Log-scale toggle
- [x] Distribution charts (doughnut = dataset split)
- [ ] Coverage matrix (sources × ref providers) — next
- [ ] Missing refs view — next
- [x] Uptime/history health (health history client-side)
- [ ] Failure retry UI — next
- [ ] Slow-request profile — next
- [ ] Strategy benchmark charts — next
- [ ] Merge sampler (agree/disagree) — next
- [x] Theme toggle (dark default, persisted)
- [x] Time-range presets (1m/5m/1h)
- [x] Table sort/filter/export (jobs sort; CSV export — next)
- [ ] Scheduler view — next
- [ ] Pipeline DAG view — next
- [ ] Manga support in UI (panel) — next
- [ ] Job diff view — next
- [ ] Docs & screenshots — next

## Batch 2 (17 selected)
- [x] Job controls (pause/resume via control files)
- [ ] Scheduler UI — next
- [x] Cache stats (env + request counter)
- [x] Limiter history chart (client-side from health polls)
- [x] PWA + mobile (manifest + viewport; full install w/ service worker — next)
- [ ] Anomaly alerts (webhook) — next
- [x] Uptime percentage (health history)
- [x] Latency p95 (client-side from polls)
- [x] Confidence histogram
- [ ] Slow request log — next
- [ ] Work queue view — next
- [x] DB size gauges (/api/db)
- [x] Insert/update counters (saved/skipped/updated in telemetry)
- [x] Backup panel (/api/backup + run)
- [x] Trend deltas (client-side)
- [x] Tab-aware polling (pause when hidden)
- [ ] Tests + docs — in progress

## Not selected (roadmap backstop)
Confidence filters explore, threshold-sensitivity curve, weightage explorer,
benchmark runners, kiosk mode, multi-dashboard/team, timezone selector, cb
manual override, top-n slow, completion countdown, work-queue, group-by source.
