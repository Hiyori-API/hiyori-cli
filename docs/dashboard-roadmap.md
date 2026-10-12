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

## Dashboard v2 — One Dashboard + dedicated pages (2026-10)

Restructured from "5 widget pages" to a single live Dashboard plus purpose-built pages.

**Navigation**
1. **Dashboard** — the one live telemetry hub: KPI row, source fleet strip (drill-down), rate +
   composition charts, dataset donut, health gauges, pipeline funnel, alert strip. Collapsible
   sections, per-widget visibility, per-widget time windows, adaptive polling, change-highlight pulses.
2. **Explorer** — MongoDB browser: collection tabs, paginated/sortable table, search, prebuilt
   filters (no_MAL / dupes / orphans), column picker, row JSON drawer, single + bulk delete,
   view CSV export, ref resolver (paste URL), two-doc diff, saved queries.
3. **Management** — source start/pause/resume/retry with confirmations, scheduler config +
   run-now, backup list + download + restore (drop + mongorestore), circuit-breaker + tokens,
   rate-limiter overrides (persisted, applied at ingest start), validation-failure retry queue,
   alert acknowledgements, audit log.
4. **Quality & Analysis** — coverage matrix + missing-critical-refs + completeness radar,
   strategy benchmark (diverging bars vs baseline), threshold-sensitivity curve (estimated from
   score histogram), confidence distribution (10 bins), confidence-filtered merge sampler,
   validation trend, weightage explorer (live sliders → demo score, feeds combine --weights),
   merged-dataset export (JSONL/CSV).
5. **Logs** — slow-request table, latency buckets, validation log (source-filtered), ingestion
   run history, cache panel.
6. **Settings** — refresh interval, theme/accent/density, timezone, token rotate, webhook URL +
   test deliver, collection visibility, widget visibility, settings import/export + reset.

**Cooler charts (recharts 3.x)** — beyond basic line/bar:
- KPI sparklines (inline Area), radial gauges (Pie arc / RadialBar) for health meters
- 100%-stacked composition stream (AreaChart + stackOffset="expand") for collection share
- FunnelChart pipeline (ingest→validate→combine→export)
- RadarChart source-completeness profile
- Diverging horizontal benchmark bars vs baseline (BarChart layout=vertical)
- Threshold-sensitivity scatter/line (cumulative merges above score threshold)
- 10-bin confidence density (ComposedChart + gradient cells)
- Latency volume buckets from the slow-request feed

**New backend endpoints**: /api/explore, /api/explore/delete, /api/meta, /api/settings (GET/POST,
token rotate, webhook test), /api/backups, /api/restore, /api/failures, /api/limits,
/api/audit, /api/history, /api/histogram, /api/export (jsonl|csv), /api/sample?min=,
/api/action?cmd=start|runnow. RateLimiter honors limits overrides from storage/state/ui.json.
