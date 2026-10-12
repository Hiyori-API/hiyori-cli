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

## Ingestion fixes & manga (2026-10)

- **AniList anime past 5,000**: pageInfo caps totals at 5,000 per query. Ingest now chunks the
  catalog by `seasonYear` (1950..2026 + one no-year pass) — proven past the cap (5,065+ and
  climbing). Manga continues past the cap similarly via pagination term on short/empty pages;
  a startDate-window variant can be added for full manga coverage (manga have no seasonYear).
- **Kitsu offset pagination**: lists are `page[offset]`-based; the manga loop stepped offsets by
  +1 (re-fetching overlapping 20-entry windows). Fixed to +20 stepping in both Kitsu loops.
- **Manga ingestion repaired**: Kitsu + AniList manga were reusing the ANIME list endpoints
  (format `tv` → manga model crash). Added dedicated `/edge/manga` requests and a manga-flavored
  AniList GraphQL query (chapters/volumes); per-source telemetry keys + locks
  (`anilist_manga` / `kitsu_manga` / `myanimelist_manga`). MAL manga ranking endpoint was correct.
- **Manga Status**: added `tba`/`unreleased` → not_yet_published; Kitsu manga synonyms filtered
  to strings (removes validator noise).
- Management page source list now uses the real manga keys + display labels.

## Alerting fixes (2026-10)

- "External APIs unreachable" banner was firing on transient blips: `healthy` was
  `status in 2xx` so a 429 (rate limit) counted as "unreachable", and Symfony followed
  MAL's throttling redirect (307 -> api.myanimelist.net/error.json) into a hanging page
  that timed out.
- HealthCheck now distinguishes `reachable` (the API answered) from `healthy` (2xx):
  - 429/3xx/5xx = reachable + degraded, never "unreachable"
  - transport error / timeout = unreachable
  - probes: timeout 10s, max_redirects 0 (don't chase error.json)
- alerts() debounces: the critical banner needs 2+ consecutive unreachable polls within
  120s; a sustained degraded state (3+ polls of 429/307) produces a soft WARN instead.

## Benchmark v2 results (anime, base anilist)

| strategy | wall | organized | has MAL | has Kitsu | triple | dups |
|---|---|---|---|---|---|---|
| relational_mapping | 264s | 4,677 | 4,677 | 3,045 | 3,045 | 0 |
| relational_fuzzy | 859s | 6,873 | 6,865 | 3,393 | 3,392 | 0 |
| metadata_fuzzy (tuned) | 1,429s | 10,770 | 10,768 | 8,359 | 8,358 | 0 |

- metadata_fuzzy with weightage-tuned weights wins on coverage by ~1.6x organized /
  2.5x triple-linked. `organized` = 10,770 (histogram: 70–80=3,705 · 80–90=680 · 90–100=6,385).
- metadata_fuzzy is now the DEFAULT combine strategy with tuned weights embedded.
- Dev: `npm run dev` serves HMR at http://localhost:5173 (proxy → :8080); port pinned via
  strictPort + `@` alias added.
