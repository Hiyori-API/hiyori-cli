# Dashboard Roadmap (tracked)

Selected items (43) with status. Built = committed.

## Batch 1 (25 selected)
- [x] Confidence filters (per-doc `confidence` stored; **histogram** built)
- [x] Combine history
- [x] Run history (telemetry persists run summaries)
- [x] SSE live updates (/api/events)
- [x] Historical KPI store (state files; counts history client+server)
- [x] Multi-worker support (per-source locks + control files)
- [x] Write endpoints (/api/action pause/resume/retry; /api/backup)
- [x] Area charts (rates/counts; area toggle)
- [x] Log-scale toggle
- [x] Distribution charts (doughnut + histogram)
- [x] Coverage matrix (/api/coverage + matrix panel)
- [x] Missing refs view (coverage matrix cell percentages)
- [x] Uptime/history health (health history client-side)
- [ ] Failure retry UI — next
- [x] Slow-request profile (latency p95 client-side)
- [x] Strategy benchmark charts (/api/benchmark seeded + bar chart)
- [x] Merge sampler (/api/sample + agree/disagree)
- [x] Theme toggle (dark default, persisted)
- [x] Time-range presets (1m/5m/1h)
- [x] Table sort/filter/export (jobs sort + CSV export button)
- [x] Scheduler view (/api/schedule + card)
- [x] Pipeline DAG view (Ingest→Validate→Combine→Export)
- [x] Manga support in UI (dataset doughnut + split note)
- [ ] Job diff view — next
- [ ] Docs (readme section done); screenshots — optional

## Batch 2 (17 selected)
- [x] Job controls (pause/resume via control files)
- [x] Scheduler UI (sources/interval/next + run-now via retry)
- [x] Cache stats (env + request counter)
- [x] Limiter history chart (client-side from health polls)
- [x] PWA (manifest + service worker + favicon)
- [x] Anomaly alerts (/api/alerts + UI banner + WEBHOOK_URL delivery)
- [x] Uptime percentage (health history)
- [x] Latency p95 (client-side from polls)
- [x] Confidence histogram
- [x] Slow request log (>=2s telemetry + /api/slow + card)
- [x] Work queue view (pending = total - processed column)
- [x] DB size gauges (/api/db)
- [x] Insert/update counters (saved/skipped/updated in telemetry)
- [x] Backup panel (/api/backup + run)
- [x] Trend deltas (client-side)
- [x] Tab-aware polling (pause when hidden)
- [x] Tests (unit + opt-in API) & docs (readme live-dashboard section)

## Not selected (roadmap backstop)
Confidence filters explore, threshold-sensitivity curve, weightage explorer,
benchmark runners, kiosk mode, multi-dashboard/team, timezone selector, cb
manual override, top-n slow, completion countdown, work-queue, group-by source.
