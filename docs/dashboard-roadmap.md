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

## Manga combine (organized_manga) (2026-10)

The combiner can now target a per-call collection via `--into` (default `organized`),
which lets manga merges land in a **new** `organized_manga` collection without touching
the anime `organized` result.

**What changed**

- `combine` gained `--into <collection>` (CombinerCommand) and threads it through
  `Hiyori::combine` → strategy config → `Database::mergeIntoOrganized` (which already
  accepted a collection argument).
- Strategies (`relational_mapping`, `relational_fuzzy`, `metadata_fuzzy`) read the
  target collection from config and pass it to every merge.
- **Manga key resolution** (`Helper::resolveReferenceId`): manga sources ingest their
  canonical reference keys (`al`/`k`/`mal`) while the combine config declares mangafied
  shorthands (`al_manga`/`k_manga`/`mal_manga`). The strategies now resolve the base
  entry's id with a manga-shorthand fallback and stamp the mangafied key into the merged
  document, so `organized_manga` docs are keyed by `reference_ids.al_manga` (etc.) and
  dedup correctly. Reference-less entries (no own identifier) are skipped instead of
  collapsing onto an empty upsert key.
- **Manga model round-trip** (`Helper::mergeEntries`): `_manga` base tables merge via
  `MangaBase` so `chapters`/`volumes` survive into `organized_manga` (anime tables keep
  `AnimeBase`).

**How to run**

```bash
# One strategy, e.g. the default tuned metadata_fuzzy:
php hiyori combine anilist_manga --strategy metadata_fuzzy --into organized_manga

# Full manga benchmark (3 strategies, logs to storage/logs/strategy-benchmark-manga.log):
bash benchmark-manga.sh
```

`benchmark-manga.sh` mirrors `benchmark-v2.sh`: for each strategy it clears
`organized_manga`, runs `combine anilist_manga --into organized_manga`, then measures
wall time + `organized_manga` stats (total, has al_manga/mal_manga/k_manga,
triple-linked, duplicate `al_manga` keys). The **final** strategy result
(`metadata_fuzzy`) is left in place; `organized` is never touched.

**Caveats observed on live data**

- Manga source tables carry few cross-references yet: only ~3.1k of ~23k `anilist_manga`
  docs have `reference_ids`; `kitsu_manga`/`myanimelist_manga` rows currently have none,
  so relational strategies for manga are limited by reference coverage (they also match
  same-numeric-id anime rows because the strategies iterate every configured source —
  pre-existing behavior, unchanged here).
- `metadata_fuzzy` manga merges start from the ~3.1k reference-bearing base rows.

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

## PWA + real-time (2026-10)

- **PWA caching**: web/public/sw.js upgraded to `hiyori-v3` —
  - /api/* GET: stale-while-revalidate → page reloads return the last-known
    payload instantly, refreshed in the background (no full reload graph).
  - /api/events (SSE): network only. Navigation + hashed assets: cache-first
    (offline-capable). Cache versioning + cleanup; skipWaiting/clients.claim.
  - SW registration guarded to skip the Vite dev port (5173).
- **Real-time option**: `/api/events` SSE stream. StatusProvider gains a
  `realtime` mode (Settings pref): full payload + slow 15s heartbeat, with
  counts/jobs pushed instantly over SSE and merged into the UI. A **Live/Live
  off** toggle sits in the top bar; header shows "live" vs the poll interval.
  Enable via prefs.realtime (Settings → Live dashboard) or the header toggle.
- Verify: build clean; /sw.js 200 with new strategy; SSE smoke OK.

## Settings → Environment manager (2026-10)

- GET /api/env lists .env vars: key, present, sensitive flag, masked value
  (secrets shown as `abcd••• (n chars)`); `?reveal=KEY` returns one plaintext.
- POST /api/env {key,value} upserts; {key,value:null} removes the line.
  Key validation `[A-Za-z_][A-Za-z0-9_]*`; single-line values; audited.
- UI: Settings → Environment (web/src/components/pages/settings-env.tsx):
  Sensitive + Configuration groups, per-row edit/reveal/delete, add-variable form.
- **FOUND + FIXED (systemic)**: public/index.php used `dirname(__DIR__, 2)`
  (→ one level above the repo) for the base path — dashboard state, backups,
  settings prefs and env writes were landing in a stray
  /home/nekomata/projects/storage/ and /home/nekomata/projects/.env all session,
  while ingestion Telemetry (src/) correctly wrote the repo's storage/. All 16
  occurrences fixed to dirname(__DIR__); stray dirs migrated into the repo;
  added a /storage/backups/*.gz download route (backups were never servable).

## Explorer backend wave (2026-10)

Backend support for the Explorer page (collection browser): facets, numeric
ranges, single/bulk edits, undoable deletes, CSV import and per-field
missingness. All endpoints are JSON, token-protected by the existing
`DASHBOARD_TOKEN` check (query `?token=` or `X-Dashboard-Token`), and every
mutating call is written to `storage/state/audit.jsonl` via `auditAppend`.
Filter/facet/CSV logic lives in `src/Service/Explorer.php` (DB-less, unit
tested in `tests/Unit/ExploreQueryTest.php`).

### Regression restored: /api/histogram

`GET /api/histogram?collection=organized` (the route had been dropped from
`public/index.php`; the handler survived). 10 bins, width 10, combining the
`score` + `confidence` fields.

```json
{ "histogram": { "0": 0, "10": 0, "...": 0, "90": 6385 },
  "bins": [0,10,20,30,40,50,60,70,80,90],
  "collection": "organized", "timestamp": "2026-10-13T00:22:41+00:00" }
```

`bins` are the bin lower bounds; `histogram` is keyed by the same lower
bounds. `?collection=` defaults to `organized`; an invalid name → 400.

### GET /api/explore (extended)

Existing params unchanged (`collection`, `page`, `per`, `q`, `sort`, `dir`,
`filter`). Two additions:

- **`facets`** — comma list, e.g. `?facets=format,status`. Response gains:

  ```json
  "facets": { "format": [ { "v": "TV", "n": 3970 }, { "v": "movie", "n": 1829 } ],
              "status": [ { "v": "finished", "n": 10737 } ] }
  ```

  One aggregation per field (not per doc), top 12 string values, count
  descending, empty values skipped, honours the active `q`/`filter`/ranges.
  Invalid field names are skipped.

- **numeric ranges** — `episodes_min` / `episodes_max` (on `episodes`) and
  `year_min` / `year_max` (on `year` **or** `start_date.year`, whichever the
  collection carries). Non-numeric values are ignored. Applied into the same
  Mongo filter as search/filter (wrapped in `$and` when needed).

### POST /api/explore/update

Body `{collection, id, field, value}` → sets one scalar field via `$set`;
`value: null` unsets the field. `field` must match `[A-Za-z0-9_.]+` (dots
allowed, e.g. `start_date.year`) and may not be `_id`. `id` is coerced to an
`ObjectId` when it looks like a 24-hex string, otherwise matched as a string.

```json
{ "collection": "organized", "id": "6acd…e9cd", "field": "status",
  "matched": 1, "modified": 1, "timestamp": "…" }
```

Audit action: `explore.update`.

### POST /api/explore/bulk

Body `{collection, ids: [], action: 'set'|'delete'|'delete_soft', key?, value?}`.

- `set` — `updateMany` `$set` (or `$unset` when `value: null`) of `key` over
  `ids`. → `{action, collection, affected, matched, modified, timestamp}`
  (`affected` = matched count). Audit: `explore.bulk.set`.
- `delete` — **first** copies the docs to `explore_undo_<collection>` with
  `_deletedAt` + `_batch` (both `time()`), then `deleteMany`. →
  `{action, collection, affected, snapshotted, batch, timestamp}` (`affected`
  = deleted count). Audit: `explore.bulk.delete`.
- `delete_soft` — same snapshot, but keeps a copy **in place** by flagging the
  docs with `_deletedAt` instead of removing them. →
  `{action, collection, affected, snapshotted, batch, timestamp}`
  (`affected` = flagged count). Audit: `explore.bulk.delete_soft`.

`ids` is capped at 10 000; empty → 400.

### GET/POST /api/explore/undo

- `GET ?collection=` → recent batches, newest first (max 20):

  ```json
  { "collection": "organized",
    "batches": [ { "batch": 1791851008, "count": 2, "time": 1791851008,
                   "seconds_ago": 0 } ], "timestamp": "…" }
  ```

  Lets the UI offer “Undo last delete (n docs, 4m ago)”.

- `POST {collection, batch?}` → restores the most recent (or given) batch
  from `explore_undo_<collection>` back into the collection (bulk upsert, so
  hard-deleted docs are re-inserted and soft-deleted docs are un-flagged),
  then removes the batch from undo.

  ```json
  { "collection": "organized", "batch": 1791851008,
    "restored": 2, "removed": 2, "timestamp": "…" }
  ```

  Audit: `explore.undo`.

### POST /api/explore/import

Body `{collection, csv, keyField: '_id'}` → parses CSV (quoted fields, `""`
escapes, CRLF/LF, embedded newlines) and upserts each row via `bulkWrite`
(`updateOne` + `upsert`), keyed by `keyField`. Capped at 10 000 data rows.
When `keyField` is `_id`, 24-hex values are coerced to `ObjectId`. Cell values
are imported as strings.

```json
{ "collection": "organized", "imported": 5, "updated": 0, "matched": 0,
  "rows": 5, "skipped": 0, "timestamp": "…" }
```

`imported` = upserted (new), `updated` = modified, `skipped` = rows missing
the key. Audit: `explore.import`.

### GET /api/meta (extended)

Existing `count`/`fields` kept; `fields` is now capped at 24. Each collection
also gains `missing` — null/missing counts for those fields, computed in one
`$ifNull`+`$group` aggregation per collection:

```json
{ "collections": { "organized": {
    "count": 10770,
    "fields": ["_id", "title", "…"],
    "missing": { "episodes": 25, "chapters": 10770, "volumes": 10770 } } },
  "timestamp": "…" }
```
