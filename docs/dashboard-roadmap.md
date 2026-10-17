# Hiyori CLI — Docs

| Doc | Status | Purpose |
|---|---|---|
| **dashboard-roadmap.md** (this file) | **Living tracker** | Feature progress for the dashboard, explorer, combiner and manga work, plus engineering notes |
| [explorer-ux-plan.md](explorer-ux-plan.md) | Plan — largely shipped | Explorer UX overhaul + TanStack DataTable checklist |
| [live-dashboard-plan.md](live-dashboard-plan.md) | **Shipped — superseded** | Original live-dashboard plan; folded into this roadmap and the readme's [Live dashboard](../readme.md#live-dashboard) section |
| [ml-augmentation-design.md](ml-augmentation-design.md) | Design — not implemented | v2 (Embeddings + LLM) combine-decision augmentation; tracked as **Batch 4** |

---

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
- [x] Failure retry UI (Management page → retry queue + run-now)
- [x] Slow-request profile (latency p95 client-side)
- [x] Strategy benchmark charts (/api/benchmark seeded + bar chart)
- [x] Merge sampler (/api/sample + agree/disagree)
- [x] Theme toggle (dark default, persisted)
- [x] Time-range presets (1m/5m/1h)
- [x] Table sort/filter/export (jobs sort + CSV export button)
- [x] Scheduler view (/api/schedule + card)
- [x] Pipeline DAG view (Ingest→Validate→Combine→Export)
- [x] Manga support in UI (dataset doughnut + split note)
- [x] Job diff view (Explorer → two-doc diff + row drawer)
- [x] Docs (readme section done)

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

## Batch 3 — Export / Publish / IDs (2026-10, all shipped)
- [x] **Hiyori IDs** — migration v3, global sequence, `_hiyori_ids` registry, unique index, `hiyori-id` CLI (report/verify/backfill)
- [x] **Per-entry JSON dump** — `data/<kind>/<hiyori_id>-<slug>.json` + `manifest.json` (sha256 + content_hash), `index/lookup.json`, `data/*.jsonl`, `data/tables/*.csv`, `CHANGELOG.md`, `schema/hiyori.schema.json`, bundle `.tar.gz`
- [x] **Merged references** — single `references` object per source with `{id, url?}`; no separate `links` map; `legacy_id` removed
- [x] **Schema-v2 toggles** — `tags`, `genres`, `synopsis`, `score`, `studios` as opt-in (off by default)
- [x] **Export profiles** — `per-entry-json` (default) / `bulk-jsonl` / `tables-csv`; configurable via `hiyori.export.json` + dashboard overrides
- [x] **Publish CLI** — `php hiyori publish` / `php hiyori db:push`; dry-run, profile selection, `--repo`, `--branch`, `--no-manga`, `--no-gzip`
- [x] **Publish Dashboard page** — config form (profile/entities/filename/fields/include), live preview, dry-run, run export (409-aware), guarded CLI-copy publish button
- [x] **Settings → Export & Publish** — mirrored profile selector, link to Publish page
- [x] **Read-only `/output/` browser** — http://localhost:8080/output/ for reviewing the latest dump
- [x] **CI Publish workflow** — `.github/workflows/publish.yml` (tag push / manual dispatch → setup → migrate → combine → publish)
- [x] **Combiner auto-ID allocation** — all 3 strategies call `Database::allocateHiyoriId()` before merge; reuse existing ID from registry

## Not selected (roadmap backstop)
Confidence filters explore, threshold-sensitivity curve, weightage explorer,
benchmark runners, kiosk mode, multi-dashboard/team, timezone selector, cb
manual override, top-n slow, completion countdown, work-queue, group-by source.

## Not selected (roadmap backstop)
Confidence filters explore, threshold-sensitivity curve, weightage explorer,
benchmark runners, kiosk mode, multi-dashboard/team, timezone selector, cb
manual override, top-n slow, completion countdown, work-queue, group-by source.

## Live dashboard — plan → shipped (docs/live-dashboard-plan.md superseded)

The original plan (`docs/live-dashboard-plan.md`) is fully implemented and its
details now live in the readme's **Live dashboard** section and the sections
below. What shipped, including where it diverged from the plan:

- **Single-process server** — `dashboard:serve` starts `php -S` on
  `public/index.php`; the same process serves the SPA, the JSON API, the SSE
  stream and `/metrics`. No Node server, Redis or extra infrastructure.
- **Polling default, SSE opt-in** — `/api/status` polling is the default;
  `realtime` mode (Settings pref + top-bar **Live/Live off** toggle) pushes
  snapshots over `/api/events` with 15s heartbeat. *Divergence:* originally
  planned as 3s polling with hand-rolled canvas sparklines in a single
  `dashboard.html`; the frontend shipped as a **React 19 + shadcn/ui SPA**
  (Vite, TanStack DataTable/Virtual, Recharts).
- **Telemetry files over log parsing** — `storage/state/ingest-<source>.json`,
  `validation-log.jsonl`, `slow-requests.jsonl`, `combine-latest.json`,
  `audit.jsonl`, `ui.json`; written atomically by `src/Service/Telemetry.php`.
- **Reused services** — the dashboard is a view over `HealthCheck`,
  `MetricsCollector`, `Validator` and `RateLimiter`; no duplicated logic.
- **Endpoint growth** — from the planned 8 endpoints to the full set listed in
  the readme (adds `/api/explore*`, `/api/env`, `/api/settings`, `/api/meta`,
  `/api/histogram`, `/api/backups`, `/api/restore`).
- **Auth** — optional `DASHBOARD_TOKEN` (`?token=` or `X-Dashboard-Token`
  header), local-only bind by default (`127.0.0.1`).

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

- **PWA caching**: web/public/sw.js is at `hiyori-v4` —
  - /api/* GET: **network-first**, with the cache used only as an offline
    fallback. (v3 was stale-while-revalidate, which served the previous payload
    on every load: harmless for polling surfaces, but it pinned stale data for
    the whole session on one-shot reads like `/api/sample`, `/api/failures` and
    `/api/benchmark` — clicking "Draw a sample" could return a superseded body.)
  - /api/events (SSE): network only. Navigation + hashed assets: cache-first
    (offline-capable). Cache versioning + cleanup; skipWaiting/clients.claim.
  - SW registration guarded to skip the Vite dev port (5173).
  - Note: after a rebuild the first load may still be served by the *previous*
    worker until the new one activates (the SW-update toast offers the reload).
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

## Alert delivery + regex search (2026-10)

### Multi-channel alert delivery

`alerts()` now hands every generated alert to
`Hiyori\Service\AlertNotifier::deliver($alerts, $telemetry)`, which fans each
alert out to **every configured channel**, throttled once per
channel+alert identity per hour in the existing `alerts-sent` telemetry store
(`storage/state/alerts-sent.json`, keys `"<channel>:<type>:<source>"`, e.g.
`telegram:circuit_open:anilist`). The old webhook-only path is unchanged in
payload/timeout; it now lives under the `webhook:` throttle prefix. Delivery is
best-effort — transport failures are swallowed and never raise.

| channel | env vars | transport |
|---|---|---|
| webhook | `WEBHOOK_URL` | one POST per alert, `{"text": "[Hiyori] <msg>"}`, 5s timeout |
| telegram | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` | `POST https://api.telegram.org/bot<token>/sendMessage` per batch, `{chat_id, text, parse_mode: "Markdown"}`, 5s timeout, try/catch |
| email | `SMTP_HOST`, `SMTP_PORT` (default 25), `SMTP_SECURE` (`tls`→STARTTLS, `ssl`, empty→plaintext), `SMTP_USER`/`SMTP_PASS` (optional AUTH LOGIN), `SMTP_FROM` (default `SMTP_USER` or `no-reply@localhost`), `ALERT_EMAIL_TO` | minimal RFC5321 client (EHLO, STARTTLS, AUTH LOGIN, one RFC5322 message) — or `mail()` fallback when `SMTP_HOST` is unset and `mail()` exists |

Messages are one line per alert (`[Hiyori] CRITICAL circuit_open (anilist):
Circuit breaker open for anilist`). Telegram text is Markdown-escaped, and
every builder redacts configured secrets (`TELEGRAM_BOT_TOKEN`,
`TELEGRAM_CHAT_ID`, `SMTP_USER`, `SMTP_PASS`, `WEBHOOK_URL`) before sending.

### /api/explore?regex=1

`GET /api/explore` accepts `regex=1`: the `q` search term is used as a **raw
PCRE pattern** instead of being `preg_quote`d (powers the frontend regex
toggle). Escaping stays minimal and security-safe:

- The pattern only runs when it compiles under PCRE and is ≤
  `Explorer::MAX_REGEX_LENGTH` (512) chars — invalid or oversized input falls
  back silently to escaped-literal matching (never an error response).
- Anchors like `^`/`$` work as written because the pattern is passed inside a
  `MongoDB\BSON\Regex` value, so there is no query-structure injection vector.
- Case-insensitive matching (`i` flag) is kept; `q` is still trimmed.

Response contract gains an echo flag:

```json
{ "collection": "organized", "regex": true, "page": 1, "per": 25, … }
```

`regex` is `true` when `?regex=1` was passed, `false` otherwise. Facets and
numeric ranges continue to honour the resulting filter unchanged.

### Tests

`tests/Unit/AlertNotifierTest.php` covers: no-channel no-op, throttle key
building, the one-hour throttle window (`due()`), webhook payload contract,
Markdown escaping + secret redaction, and email message assembly/leak checks.
`tests/Unit/ExploreQueryTest.php` gains raw-regex + fallback coverage.

## Combiner performance (2026-10)

- Symptom: metadata_fuzzy combine ~4-6 rec/s; `organized` had only `_id` → every
  candidate/ref lookup was a full collection scan per source record.
- Fix: 26 indexes created via mongosh (recipe below) — candidate compound
  `{type,status,year}` + `{title}` + `reference_ids.{al,mal,k}` on
  organized/organized_manga, and `reference_ids.*` on all six source tables.
  Benefits the running anime rebuild immediately; critical for the manga pass
  (38k base).
- Note: metadata_fuzzy is intrinsically the priciest strategy (per-record score
  over ≤100 candidates); relational_fuzzy ≈1.7× faster but ~1.6× fewer merges.
  Recipe (mongosh): createIndex({type:1,status:1,year:1},{name:"cand"}) on
  organized + organized_manga; createIndex({"reference_ids.<sh>"=>1}) per
  shorthand per table.

## Relational publish — per-entry JSON + Hiyori IDs (2026-10)

A second publish shape alongside the relational CSVs: the exporter writes **one
JSON file per entry** keyed by a stable, global `hiyori_id`, plus a manifest,
a lookup index, CSV views and a changelog. The dashboard's export API drives it
without a git push (the push stays the job of `php hiyori publish`).

### Hiyori ID allocation rules

- **Global + immutable.** A single monotonic sequence is shared by anime
  (`organized`) and manga (`organized_manga`); an id is allocated once and never
  reused or renumbered. `GET /api/entries/{id}` is therefore unambiguous.
- **Migration v3** (`allocate_hiyori_ids`) on `php hiyori migrate`, or the
  idempotent backfill `php hiyori hiyori-id --backfill`. Only docs missing
  `hiyori_id` are touched; `php hiyori hiyori-id --verify` cross-checks docs
  against the registry (exit 1 on drift).
- **Reuse on merge.** A later merge of another source *into* an entry reuses the
  id recorded for the entry's **reference set** (or its `legacy_id`) instead of
  allocating a new one — `Migrator::allocateId()` / `findRegistryId()`.
- **Registry** `_hiyori_ids` (one row per id: `hiyori_id`, `kind`, `refs`,
  `slug`, `legacy_id`, `allocated_at`); `_counters` (`_id: "hiyori_id"`) hands
  out blocks. Unique index on `hiyori_id` and on `kind + slug`; non-unique
  indexes on each `refs.<shorthand>`.
- **Slugs** — `normalizeSlug()` lowercases, collapses non-alphanumerics to `-`,
  trims, caps at 120 chars, empty → `untitled`; per-kind unique with `-2`, `-3`
  suffixes. See [schema/README.md](../schema/README.md).

### Dump layout

```
<dump>/manifest.json
      index/lookup.json                              # hiyori_id -> {kind, slug, path}
      data/<kind>/<hiyori_id>-<slug>.json            # one entry, schema-validated
      tables/*.csv                                   # flat views
      CHANGELOG.md                                   # added/removed/changed
```

`schema/hiyori.schema.json` is the draft 2020-12 JSON Schema for a per-entry
file (required `hiyori_id`, `slug`, `kind`, `title`, `references`).

### Filename scheme

The `filename` pattern must contain `{hiyori_id}` (the API rejects patterns
without it, plus `..`/leading `/`/`\`). Supported placeholders include
`{hiyori_id}` and `{slug}`; the default is `{hiyori_id}-{slug}`. The Publish UI
posts the pattern as `filename_pattern`; the API normalises that alias to
`filename` on write and mirrors it back on read.

### Profiles

`profile` selects a named preset that `\Hiyori\Service\ExportConfig` resolves
into the effective config (`layout`, `format`, `entities`, `filename`, `fields`,
`include`, `index`, `pretty`, `bundle`, `subdir`, `write_entries`). The API
validates `profile` against `ExportConfig::profileNames()` (the `PROFILES`
keys), falling back to the router's built-in list:

| profile | intent |
|---|---|
| `per-entry-json` (default) | one pretty file per entity — `data/<kind>/<hiyori_id>-<slug>.json` — plus manifest/lookup/CSV/changelog |
| `bulk-jsonl` | one JSON object per line under `data/<kind>.jsonl` |
| `tables-csv` | normalized `data/tables/anime.csv` / `manga.csv` + lookup tables |

### State overrides

Overrides live in `storage/state/export.json` (gitignored) and are merged
**key-wise** deeper-into objects (`entities`, `include`) over the
`hiyori.export.json` template; request-level overrides layer on top. Effective
config = `ExportConfig::load(storage/state/export.json)` (falling back to
template ⊕ state) ⊕ request override.

### API endpoints

All endpoints are JSON and token-protected by the existing `DASHBOARD_TOKEN`
check (`?token=` / `X-Dashboard-Token`). Mutations append to
`storage/state/audit.jsonl`.

| method | path | notes |
|---|---|---|
| GET | `/api/export-config` | `{config, template, state, timestamp}` |
| POST | `/api/export-config` | `{config:{…}}` (or top-level keys) validated + key-merged into state; `{reset:true}` clears overrides |
| GET | `/api/export/preview` | `?kind=anime\|manga` + overrides → `{entry, rendered, kind}` |
| GET | `/api/export/dry-run` | overrides → `{files, byKind{anime,manga}, sampleFilenames[], bytesEstimate}` |
| POST | `/api/export/run` | `Exporter::write` → `{dir, written, files, rows, changelog}` (no git push) |
| GET | `/api/entries/{id}` | `{entry, kind}` across anime + manga; 404 when absent |
| GET | `/api/anime/{id}` · `/api/manga/{id}` | kind-scoped variants of the above |

Overrides are passed per request with `?config=<json>` (a JSON object) and/or
dotted query keys such as `include.tags=1`, `entities.manga=0`,
`profile=compact`.

Audit actions: `export.config`, `export.run`.

```json
// GET /api/export/preview?kind=anime&include.tags=1
{ "entry": { "hiyori_id": 42, "slug": "fullmetal-alchemist-brotherhood", "kind": "anime", "…": "…" },
  "rendered": "…", "kind": "anime" }

// POST /api/export-config { "config": { "profile": "compact", "include": { "tags": true } } }
{ "config": { "profile": "compact", "…": "…" }, "state": { "profile": "compact" } }

// POST /api/export/run
{ "dir": "storage/publish/hiyori-relational-20261014-120000",
  "written": { "…": 0 }, "files": 23212, "rows": 23212, "changelog": "…" }
```

### Failure modes

- `\Hiyori\Service\Exporter` missing → `503 {"error":"exporter unavailable"}`.
- `\Hiyori\Service\ExportConfig` missing → the router falls back to
  template ⊕ state, so GET `/api/export-config` still answers.
- Combine running (`pgrep -f "php hiyori combine"` matches) → POST
  `/api/export/run` returns `409 {"error":"combine in progress"}` so a dump is
  never written mid-merge.
- Invalid config → `400 {"error":"invalid export config","errors":[…]}`.

Unit coverage for the pure helpers (filename-pattern + shape validation,
key-wise merge) lives in `tests/Unit/ExportHelpersTest.php`.

## Hiyori IDs — stable global identifiers (2026-10)

Each entry in `organized` (anime) and `organized_manga` (manga) carries a
`hiyori_id`: a **global** (not per-kind), monotonically increasing integer that
is allocated **once** and never re-used or shifted. A later rebuild that
re-inserts a doc, or a merge that folds a new source INTO an existing entry,
must reuse the already-allocated id rather than hand out a new one.

**Allocation.** `Migrator` migration v3 `allocate_hiyori_ids` streams every doc
missing `hiyori_id`, assigns ids sequentially from the `_counters` counter and
pushes `{hiyori_id, slug}` onto the doc, then creates the unique index
`hiyori_id_1` (`{hiyori_id: 1}`, `unique: true`) on each collection. It is
idempotent (re-running finds nothing missing) and fails loudly if a duplicate id
is detected. The counter alone is not authoritative: `Migrator::allocateId()`
recovers an existing id for a known reference set so the combiner can call it
for new/merged entries without shifting anything.

**Registry.** `_hiyori_ids` persists every allocation so ids are reproducible
across a full rebuild (keyed by the entry's reference set / legacy id):

```json
{
  "_id": ObjectId("..."),
  "hiyori_id": 1,
  "kind": "anime",                     // 'anime' | 'manga'
  "refs": { "al": "1", "mal": "1", "k": "1", "ap": "B00R2KO8ZE" },
  "slug": "cowboy-bebop",
  "legacy_id": "6acd5cefeb712a640837e101",
  "allocated_at": ISODate("...")
}
```

The next id lives in `_counters` as `{ "_id": "hiyori_id", "next": <int> }`.
Registry indexes: unique `hiyori_id_1`, unique `kind_slug_1` (`{kind, slug}`),
and a non-unique `refs_<shorthand>_1` per reference shorthand.

**Slug.** Title-normalized: lowercase, every run of non-alphanumerics → `-`,
collapsed and trimmed, capped at 120 chars (`untitled` when empty). Duplicates
within a kind are suffixed `-2`, `-3`, … (`Migrator::normalizeSlug()` /
`Migrator::uniqueSlug()`).

**Command.** `php hiyori hiyori-id` (alias `ids`) reports counts
(allocated/missing per collection, next id, registry size, duplicate count).
`--backfill` runs allocation without recording the migration, and `--verify`
checks that every doc has an id, ids are unique, docs and registry agree in both
directions, and slugs are unique per kind (prints up to 20 problems; non-zero
exit on failure). `--json` emits machine-readable output.

## Batch 4 — Compressed release pipeline (design, 2026-10-15)

Design doc: [`release-pipeline-design.md`](release-pipeline-design.md). The command surface and packaging
landed in `ReleaseCommand` / `scripts/publish-release.sh`; remaining boxes are the mutable `latest` alias and
the acceptance checks that need a real remote.

### 4.1 Limits & policy (docs first)
- [x] Document GitHub limits and which bind us (100 MB per-file on push/web, 2 GB hard object cap, 1 GB repo soft limit, ~100k file-count pain, 150 MB index class, LFS quotas, 2 GB release-asset cap) → `docs/release-pipeline-design.md` §2
- [x] Record current dump footprint baseline (15,890 files / ~94 MB on-disk / ~50 MB bytes / 9.6 MB after `tar --zstd`) and re-measure each release
- [x] Decide Tier A/B/C policy: Tier A management repo code-only; Tier B release assets; Tier C no LFS for this dataset, `latest.json` pointer in repo
- [x] Write the manami-style vs LFS vs trunk-only vs release+LFS comparison into the design doc (done)

### 4.2 Tagging
- [x] Adopt tag grammar `data-YYYY.WW` (append `.N` on hash change) + optional `vYYYY.MM.PATCH` milestone + mutable `latest` alias
- [x] Define annotated-tag / release body template carrying `content_hash`, counts, `generated_at`, `source_commit`, asset list, verify commands
- [x] Implement content_hash-based idempotency: same `content_hash` → exit 0; differing hash → `.N` suffix
- [x] Add `source_commit` recording to release notes so a release is reproducible from `hiyori-cli` at a known commit

### 4.3 Script & compression
- [x] Create `scripts/publish-release.sh` from the skeleton in design doc §5.2 (bash, `set -euo pipefail`, cleanup trap)
- [x] Build dump via `php hiyori export organized --profile per-entry-json` (or reuse `HIYORI_DUMP_DIR` from PHP)
- [x] Verify manifest with `sha256sum -c` before any publish step (`--verify` equivalent)
- [x] Produce single `hiyori-db-<tag>.tar.zst` (~9.6 MB today) with deterministic headers (`--sort=name`, `SOURCE_DATE_EPOCH`)
- [x] Add optional sidecars: `*.anime.jsonl.zst`, `*.manga.jsonl.zst`, `*.tables.zip`, `*.schema.zip`
- [x] Split any asset above 1.9 GB with `split -b 1900M … .part` and reject > 2 GB (release-asset cap)
- [x] Write `SHA256SUMS.txt` over every asset (including split parts)
- [x] Implement `DRY_RUN=1` mode that stops after asset creation + verification (`release --dry-run`)
- [x] Support gh-CLI-unavailable fallback: follow-up GitHub commands printed, pointer push attempted

### 4.4 Target repo / manami-style cleanup
- [x] On publish, remove legacy raw tree from `HiyoriDB` `master` (`data/`, `index/`, `manifest.json`, `CHANGELOG.md`, tarballs) via opt-in `release --clean-target`
- [x] Add `.hiyori-release/latest.json` pointer (`tag`, `content_hash`, counts, `generated_at`, `source_commit`, asset list)
- [ ] Update/move mutable `latest` tag + GitHub "latest" release after each run

### 4.5 Command surface changes
- [x] Add `--verify` to `PublishCommand` (`php hiyori publish --verify`): validate manifest checksums/sizes
- [x] Add diff-stat gate before push (`--max-changed N`, `--force` override) using `Exporter::write()` `$result['changed']`
- [x] Add `ReleaseCommand` (`php hiyori release` / alias `db:release`) with `--repo`, `--branch`, `--tag`, `--profile`, `--no-manga`, `--draft`, `--verify`, `--dry-run`, `-m`
- [x] Keep `publish` (raw-tree) working for small/emergency updates; deprecate only after one stable release cycle

### 4.6 Rollback / reproducibility / CI
- [x] Document consumer download + verify procedure (gh release download + `sha256sum -c SHA256SUMS.txt`)
- [x] Document maintainer rollback (do not delete releases; move `latest` + publish `.N`; secret-leak exception path)
- [x] Document reproduce-from-source steps using `source_commit` + `content_hash` comparison
- [x] Update `.github/workflows/publish.yml` to call `php hiyori release` (or the script) instead of `php hiyori publish` (weekly cron/DRY_RUN polish pending)
- [ ] Add an annual re-evaluation of Tier C / LFS if a second consumer needs `git checkout` of the full tree

### 4.7 Verification checklist (acceptance criteria)
- [x] `DRY_RUN=1 scripts/publish-release.sh` equivalent (`php hiyori release --dry-run`) produces a < 2 GB asset set and exits 0 without network push
- [x] Re-running the full script against an unchanged dump exits 0 without creating a duplicate release (state-based content-hash gate, `--force` to override)
- [ ] A changed `content_hash` produces a new `.N` tag and a new release, while preserving the original
- [x] Consumer can download `data-YYYY.WW`, run `sha256sum -c SHA256SUMS.txt`, extract with `tar --zstd`, and read the same `content_hash` from `manifest.json` (verified locally)
- [ ] `HiyoriDB` `master` no longer contains `data/<kind>/*.json` after migration, but still hosts `schema/` + `.hiyori-release/latest.json`


## Batch 4 — ML / Augmentation (v2 Embeddings + LLM)

Reference design: [ml-augmentation-design.md](ml-augmentation-design.md). **Level-0 (local, free)** is shipped:
`EmbeddingService`, `SemanticRerankStrategy`, and the optional `services/ml/embed.py` sidecar.
**Level-0.5** is also shipped: `ml:export-pairs` (labelled JSONL from the `organized` ground truth),
`ml:train` (pure-PHP logistic regression), `FeatureExtractor`/`LogisticModel`, and the `metadata_ml`
combiner strategy (second-stage scorer with `anchor_rejection`). The LLM judge (Level-1) and a calibrated
classifier with XGBoost + migration v4 (Level-2) remain design only.

### 4.1 Feature engineering
- [x] Title lexical features: Levenshtein, Jaro-Winkler, N-gram cosine, token sort ratio, containment, exact-normalized match
- [x] Structured match features (type/episodes/status/year/season/source-ref overlap) re-exposed in feature vector
- [x] Multilingual title embeddings (title + synonyms blob) via sentence-transformers (`services/ml/embed.py`, n-gram fallback)
- [ ] Cross-source tag/genre taxonomy + canonical tag overlap features
- [ ] Content-hash idempotency (`content_hash`) for embeddings and feature rows
- [x] Feature JSONL export (`ml:export-pairs`) for offline training/eval

### 4.2 Embeddings sidecar
- [x] Python sidecar (sentence-transformers) invoked from PHP per line (`ML_EMBED_COMMAND`, Option A)
- [x] Model selection + config: MiniLM-L12 / LaBSE / MiniLM-L6 (`ML_MODEL`, passed per-request to the sidecar)
- [x] `embeddings` Mongo cache collection keyed by `(content_hash, model, model_version)` — `EmbeddingService` reads through and writes through to the collection when a `Database` is injected (the `semantic_rerank` strategy does); the JSON file cache (`storage/ml/embeddings.json`) remains the no-database fallback.
- [x] Recompute only when content hash or model/version changes (cache key = `(model, version, sha1(text))`; model/version mismatch discards stale vectors)
- [x] Venv + pinned `requirements.txt` (`services/ml/requirements.txt`) + smoke test (documented in `services/ml/README.md`)

### 4.3 LLM-as-judge
- [x] OpenAI/Anthropic judge for ambiguous band (70–85), JSON-schema output — `LlmJudge` sends a compact `{title,synonyms,type,episodes,status,year,season,tags_canonical,source}` pair and parses strict JSON `{match,confidence,reason}` (markdown fences tolerated).
- [x] Invoke directly from PHP (Option B) with per-run cap + budget guard — `LLM_MAX_PAIRS_PER_RUN` (2000) + `LLM_BUDGET_USD` (5.00), both hard guards; cache hits are consulted *before* the guards so re-runs are free.
- [x] `llm_judgements` cache keyed by pair hash — `sha256(json_encode([a,b,model,prompt_version]))`; the model is part of the key so switching models cannot serve stale verdicts.
- [x] Telemetry for token/cost spend (`llm_tokens`, `llm_cost_usd`) — `ml:llm-judge` writes `llm-latest` + appends `llm-judge` with tokens, estimated cost, judged and skipped counts. **Activation still needs `LLM_API_KEY`** (no key = every call is a no-op, the pipeline is unchanged).

### 4.4 Decision model / classifier
- [x] Human-in-the-loop label capture (`_labels`; dashboard sampler agree/disagree → `POST /api/labels`; `labels` CLI; `ml:export-pairs` prefers human labels)
- [ ] LLM bootstrap of training labels
- [x] Train logistic regression (v2.0, pure PHP `ml:train`) / XGBoost (v2.1, pending) on feature JSONL
- [ ] Calibration (isotonic/Platt) so `ml_score` maps to 0–100 threshold
- [ ] DBSCAN title-clustering helper (optional, behind flag)
- [ ] Clef/JEB-style external scoring harness over the same feature JSONL

### 4.5 Strategy & storage
- [x] New strategy `semantic_rerank` as second-stage scorer on `metadata_fuzzy` candidates (local embeddings; LLM `metadata_ml` still pending)
- [x] Store `ml_score`, `ml_model`, `ml_model_version`, `ml_decision`, `ml_decided_at` on merges (`ml_reason` deferred)
- [x] Migration v4 (`ml_fields_and_indexes`): `confidence`/`ml_score`/`ml_decision` indexes + `embeddings`/`llm_judgements`/`pair_rejections`/`_labels` collections & unique indexes
- [x] Rejection buffer + multiclass labels (approve/reject/unsure) — `RejectionStore` over `pair_rejections` (unique on the full pair), `php hiyori rejections add|list|stats|promote`, `POST /api/rejections` + `/api/rejections/promote`, and a Quality-page buffer card.
- [x] Registration in `StrategyConfigurationFactory::AVAILABLE_STRATEGIES` (`metadata_ml`)

### 4.6 Dashboard
- [x] Explorer: opt-in `confidence`/`ml_score`/`ml_model`/`ml_model_version`/`ml_decision` columns — the column list is data-driven (the API returns each collection's `fields`) and `ml_score` already renders as an inline bar (`web/src/components/explorer/cells.tsx`).
- [x] Quality page: metadata vs ML score histograms; `/api/histogram?field=` — `field` accepts `confidence | score | ml_score` (`public/index.php`) and the Quality page renders both the merged-score and ML-score histograms.
- [x] Threshold suggestions — `combine:benchmark` stores the best threshold (predicted merges + precision/recall/F1) and `/api/benchmark` exposes it; the Quality page shows a "Suggested threshold" banner with the shipping-gate margin. Shown as a recommendation, never auto-applied
- [x] Rejection buffer review + promote-to-training — the Quality page lists the buffer with pending/promoted counts and a Promote button; `promote()` copies approve→1 / reject→0 into `_labels` and skips `unsure`.
- [ ] Model/version filter for A/B review
- [ ] Settings → Combine/ML config (model, sidecar path, caps, budget, enable toggle)

### 4.7 Validation
- [~] Offline A/B benchmark vs `metadata_fuzzy` (coverage, F1, duplicate keys = 0) — holdout F1 done (+0.107 over fuzzy); duplicate-key gate shipped as `php hiyori combine:dupes` and runs in `.github/workflows/publish.yml` after `combine`. **Correction:** the earlier "all collisions are stale legacy docs" reading was wrong — the `references` array-vs-document shape is a serialization artefact of `array_unique()` (gaps), not a schema generation, so it never distinguished old from new data. A clean rebuild reproduced the *same* `mal` collisions. Root cause and fix in the 2026-10-15 entry below.
- [ ] Idempotency/content-hash regression test for re-runs
- [~] Cost + latency documentation + shipping gate (F1 ≥ baseline +2 pts) — gate is configurable: `combine:benchmark --margin 2 --strict` exits non-zero unless `metadata_ml` beats `metadata_fuzzy` by ≥2 F1 points. Re-run on the fixed scorer 2026-10-16: **+1.2 pts, gate not met** — the earlier `+10.7 pts` compared `metadata_ml`'s best F1 against `metadata_fuzzy` at its default threshold (see the release-gate entry below). Cost/latency write-up still pending


## Batch 4 — Combiner v2 (research/design, 2026-10-15)

- [x] Research doc delivered: `docs/combiner-strategy-research.md` (baseline, failure modes, tuning grids 65/70/75/80, candidate improvements, three proposed strategies, scaffolds, rollout)
- [x] Baseline benchmark harness: `ml:export-pairs` gold-pair sampling + `combine:benchmark` threshold sweep (precision/recall/F1 at 60/65/70/75/80/85/90) with a `metadata_ml` vs `metadata_fuzzy` F1 gate report
- [x] Metadata-fuzzy candidate improvements: Unicode-safe title normalization (`Helper::normalizeTitleUnicode`) + anchor-field rejection (`anchor_rejection`, `anchor_year_tolerance`, `anchor_episodes`)
- [ ] Weight recalibration pass: 600+ labeled pairs (anime + manga), store `storage/state/weightage-*.json`, gate on precision/recall thresholds
- [x] Telemetry upgrade: `rejected_anchor`, `candidates_examined`, per-source counts, `combine-events.jsonl`, `combine-benchmark.jsonl` (additive only)
- [x] Graph connected-components strategy + unit tests (transitive cluster expansion, link/merge thresholds, cluster telemetry)
- [x] Fellegi–Sunter probabilistic strategy (configurable m/u; score + registration tests); m/u calibration from benchmark pairs still pending
- [x] Semantic re-rank strategy aligned to `docs/ml-augmentation-design.md` (embeddings interface; LLM tie-break/`ml_*` fields still pending)
- [x] Registration + CLI help for new strategies in `StrategyConfigurationFactory::AVAILABLE_STRATEGIES` (opt-in; default unchanged)
- [x] Label capture via `/api/sample` agree/disagree for precision/recall tracking (`_labels` + `labels` CLI + `/api/labels`)
- [x] **Title-guard + blank-synonym fix (2026-10-15).** Root cause of the duplicate `mal`/`al` keys: `metadata_fuzzy` merged entries with **zero title overlap** because the non-identifying weights (`type`+`status`+`episodes`+`season`+`year`) sum to 73.5, above the default threshold of 70 — observed live: "Sonic X Season 2" merged with "Mai-Otome" (kitsu) and "Mushishi" (MAL). Compounding it, `titleSimilarity()` returned **0.9** whenever both entries carried an empty-string synonym (`'' === ''` in the intersection). Fixes: `MetadataFuzzyStrategy::isTitleRejected()` with a configurable `title_floor` (default `0.30`, `0` disables) applied in `metadata_fuzzy`, `metadata_ml`, `semantic_rerank`, `graph_merge` and `fellegi_sunter`; blanks are filtered before synonym intersection. `mergeIntoOrganized` still unions the candidate's `reference_ids`, so a wrong merge contaminates keys — the guard removes the wrong merges rather than the union.
- [ ] **Staging rebuild result (2026-10-15, non-destructive `--into organized_new`).** Re-running `combine anilist` with the title guard took `mal` duplicate keys from **130 → 2** and duplicate `hiyori_id`s from 1,906 → 877, confirming the guard fixes the observed contamination. Two deeper issues keep the data from being release-clean and are the next work items:
  1. **Many-to-one candidate claiming.** `mergeIntoOrganized()` upserts by the *base* shorthand, so when two base entries match the same candidate (e.g. AniList `Pop in Q` al=21412 and `Koro-sensei Q!` al=21864 both claimed MAL `30695`), each writes its own document and the candidate's identity key is duplicated. The fix is to key/collapse on the shared candidate (or use the connected-components `graph_merge` strategy) rather than upsert per base entry.
  2. **`matchKnownId()` matches on franchise keys.** Identity reuse considers *any* shared `refs.*` key, so two unrelated entries sharing a streaming/social key (`cr`/`t`/`tx`/`nf`/`k`) are handed the *same* `hiyori_id` (the bulk of the 877 collisions). **Fixed 2026-10-16** — see below.
  Coverage also fell (13,719 → 8,998 merged docs) because unmatched base entries are not persisted — a single-source passthrough would recover them. None of this was swapped into `organized`; the collection and id registry were left at the verified baseline.
- [x] **Duplicate-key fix + release-gate unblock (2026-10-16).**
  - **Non-Latin title artefacts.** `titleSimilarity()` treated a normalized fragment like `q` — what both `殺せんせーQ！` and `ポップQ` reduce to under `normalizeTitle()` (it strips every non-`[a-z0-9]`) — as a 0.9 synonym hit *and* a containment match, re-creating the false merges the guard was meant to stop (AniList `Koro-sensei Q!` stolen MAL `30695`). Fragments shorter than `MIN_TITLE_FRAGMENT` (3) are now ignored by the synonym and containment steps, and the 1-char exact shortcut is dropped.
  - **Franchise-key identity reuse.** `Migrator::matchKnownId()` now compares **identity keys only** (`IDENTITY_REF_KEYS` = `mal`/`al` + manga variants) so streaming/social keys can no longer hand unrelated entries one `hiyori_id`.
  - **Contaminated registry rows.** A row could hold a foreign identity key from an earlier bad merge (row `6631` carried both Beelzebub's `al=13067` and Ikoku Meiro's `mal=10832`); matching on any single shared key plus `min()` then captured both works. A registry row must now **agree on every identity key it shares** with the document; a conflict vetoes the row so the genuine match wins.
  - **`combine:dedupe` (release-gate unblocker).** When the *source data itself* repeats an identity key (AniList carries ~10 duplicated `mal` values), no matcher can remove it. `combine:dedupe` drops the key from the losing document (never deletes docs, so ids/coverage stay stable) and keeps it on the owner **corroborated by that document's base ingest row** (falls back to smallest id). Applied live to `organized`: **130 → 0** `mal` duplicates; `combine:dupes` and `hyori-id --verify` both pass.
  - **Full-coverage rebuild is viable but is a product choice.** A non-destructive rebuild still merges to ~8,894 docs because unmatched base entries are dropped, which would orphan **4,830** registry ids. Verified analytically: a single-source **passthrough** (persist every base entry) would orphan **0** ids (every registry `refs.al` exists in `anilist`) and add **1,292** new ids (anilist base entries with no registry row). That is a deliberate coverage increase, not yet switched on — so the live collection was fixed in place instead of swapped. **Realised 2026-10-16:** the passthrough rebuild allocated exactly **1,298** new ids (15,881 → 17,179) and left **0** registry rows without a document — see the passthrough rebuild section above.
- [x] **Release gate re-run on the fixed scorer (2026-10-16) — not met, the default stays `metadata_fuzzy`.** `ml:export-pairs --holdout 30` (500 MAL bases; 4,231 train / 1,117 holdout rows = 167 positive, 950 negative) → `ml:train` → `combine:benchmark --margin 2 --strict`: `metadata_fuzzy` best F1 **0.9760** (threshold 75) vs `metadata_ml` best F1 **0.9880** (flat 60–90) → margin **+0.0120**, short of the required 0.0200 → `GATE NOT MET`. Two caveats the number does not carry:
  - The previously recorded `+10.7 pts` (and the `0.931` vs `0.824` pair below) compared `metadata_ml`'s **best** F1 against `metadata_fuzzy` **at its default threshold** (70 → F1 0.859 on this holdout) rather than against fuzzy's best. Best-vs-best is what `combine:benchmark` gates on, and on that basis the two are within a point.
  - The benchmark scores the exported `metadata_score` and the model probability directly — it applies **neither** the deployed `title_floor` nor anchor rejection. Both strategies run the same guards in production, so the comparison holds while the absolute F1 values are not production figures.
  - The other half of the blocker is untested here: `metadata_ml`'s duplicate `mal` keys were measured on its last full combine (3, against 0 for `metadata_fuzzy`); settling that needs a clean full `metadata_ml` rebuild into a scratch collection.
  - Only **1** of the 114 review labels landed in the export — a 500-of-10,877 base sample rarely lands on a reviewed id — so this run says little about them; they matter to `combine:consensus`'s `human` column instead.

## All-strategy benchmark (2026-10-16)

First like-for-like measurement of all seven combiner strategies — same base (`anilist`, 15,015), same revision, same candidate machinery. Full write-up: `docs/combiner-strategy-benchmark.md`.

- [x] **Harness.** `scripts/benchmark-strategies-all.sh` runs every strategy into its own `bench_<strategy>` scratch collection, snapshotting and restoring `_hiyori_ids`/`_counters` (mongosh document copy — this host's `mongodump` lacks `--nsInclude`) before each run, so each strategy starts from an identical registry and `organized`/sources are never written. 6 h 28 m for the full sweep.
- [x] **Accuracy without circular labels.** `combine:consensus` replays one `ml:export-pairs` sample (7,494 pairs, 873 `organized`-positive) against every `bench_*` collection and scores each strategy vs *consensus* (majority vote of the strategies), vs *organized*, and vs *human* review labels (column unpopulated — `_labels` is empty). Validated against synthetic data.
- [x] **Findings** (details in the write-up):
  - Coverage spans **6,805 → 11,506** docs; `relational_fuzzy` wins on volume but its extra docs are `anilist`-only (52.1 % triple-linked vs 71.1 % for `metadata_fuzzy`).
  - Accuracy clusters: `metadata_fuzzy` / `graph_merge` **0.812**, `metadata_ml` 0.810, `semantic_rerank` 0.806, `fellegi_sunter` 0.804, then `relational_fuzzy` 0.787 and `relational_mapping` **0.468** (recall 0.412).
  - **Four of seven mint duplicate `mal` keys** (`relational_mapping` 2, `relational_fuzzy` 6, `semantic_rerank` 2, `metadata_ml` 3) and would need `combine:dedupe` before release. `metadata_fuzzy`, `graph_merge`, `fellegi_sunter` pass the gate clean.
  - `metadata_fuzzy`/`graph_merge`/`fellegi_sunter` allocate ~4–5 new `hiyori_id`s; the relational strategies allocate 386/684 — ~100× more registry churn.
  - `semantic_rerank` peaks at **500 MB** (6.6×) for middling accuracy; the local n-gram fallback is not paying for itself.
  - `combine-latest` telemetry **merges rather than replaces** (`Telemetry::update` is last-write-wins per field), so shared keys leak across strategies in `bench-telemetry-*.json` — only `merges` is trustworthy per strategy.
- [x] **Default unchanged.** Nothing measured justifies switching off `metadata_fuzzy`; `metadata_ml` is the one to revisit (matches fuzzy within 0.002 F1, ~27 % faster, calibrated scores) once its 3 duplicate keys are resolved.
- [ ] Manga (`anilist_manga`, 38,097) not benchmarked.
- [~] Human labels: the loop works and `_labels` holds 118 rows (4 from the dashboard, 114 from the 2026-10-16 automated review pass, see `docs/labels/`). Enough to populate `combine:consensus`'s `human F1` column, but `ml:export-pairs` counts *any* stored label as human ground truth, so the column currently mixes both provenances.

## Passthrough rebuild (2026-10-16)

First full rebuild with `--passthrough`: `php hiyori combine anilist --passthrough --export none`, in place over the live `organized` (the 13,719 documents an earlier run left), base `anilist` 15,017, 56 minutes.

- [x] **One document per base entry.** 15,017 documents for 15,017 base entries — 9,114 merged (they carry `confidence`) + 5,903 single-source (no `confidence`). Without the flag the merge loop writes only entries that matched, so an entry that matched nothing vanished from `organized` and its registry id was left without a document.
- [x] **Ids did not move.** `_counters.next` went 15,881 → 17,179: exactly **1,298** new ids, the base entries no earlier run had covered. All 13,719 pre-existing documents kept the id they had — `hiyori-id --verify` → `[OK] All hiyori_id checks passed`.
- [x] **Registry is exact.** anime registry rows 15,017 = documents 15,017; 0 rows without a document, 0 documents without a row, 0 duplicate `hiyori_id`. Manga untouched (2,161 / 2,161).
- [x] **The rebuild cannot delete anything.** `replaceOne` upserts only, so the in-place run has no rename-or-drop window and the registry is never inconsistent mid-flight.
- [x] **Slugs come back from the registry.** The combiner stamps `hiyori_id` but deliberately leaves `slug` to `hiyori-id --backfill` — the registry is the single source of truth for unique slugs — and the backfill restored all 15,017, after which `verify` passes.
- [x] **Duplicate keys, resolved.** A rebuild regenerates `reference_ids` from the sources, so the 11 duplicate `mal` values an earlier `combine:dedupe` had stripped came back (`al` stayed clean, and the manga gate was clean). `combine:dedupe --primary mal,al` resolved them in place — 11 keys removed from the losing documents, no document deleted — and the gate then passed: `[OK] No duplicate identity keys (mal, al)`.
- [x] **The 15 sampled false merges are gone.** The review pass recorded 15 wrong references across 30 sampled documents, all AniList-vs-Kitsu merges stored at **95.86** = `72.10 + 0.9 × 26.4`, an empty-string synonym bonus on metadata-identical entries. After the rebuild **none of the 15 pairs still co-occurs**, `k:556` is on one document instead of nine, and every correct merge in the same sample is still merged. The fixes that kill them (dropping blank synonyms in `titleSimilarity()`, `title_floor`) were already shipped — the live collection was simply older than both.
- [x] **"Passthrough = one reference key" was wrong; the docs are corrected.** 166 single-source records carry one key, the other 5,737 carry 2–6: a passthrough record keeps the base entry's *own* reference set, and AniList ships `mal`/`yt`/`tx`/`nf`/`ttv`/`cr`/`ap` cross-references on the entries themselves. The **absent `confidence`** identifies a single-source record, not the shape of `reference_ids`.
- [x] **`documents` telemetry was inflated.** It reported `$totalMerged + $totalPassthrough` = 19,618 while the collection held 15,017 — `$totalMerged` counts matched *sources*, and an entry matching both myanimelist and kitsu upserts one document twice (4,601 documents here matched both). The three shipping strategies now report distinct documents as `documents` and leave `merges` as the matched-source count.
- [ ] **Interim, not the release build.** The ingest resume was running concurrently and `kitsu` grew underneath the run (8,992 → 22,503 target), so merge counts depend on when the run looked at it. Every structural result above holds regardless; the release build is re-run once the ingests settle.

## Dashboard UI/functionality pass (2026-10-16)

- [x] **Six endpoints the SPA already called did not exist** — `/api/failures`, `/api/audit`, `/api/history`, `/api/limits` (GET+POST), `/api/backups`, `/api/restore`. Management's Backups / Audit / Rate-limiter / Validation-failure panels and Logs' run history were permanently empty, and every page load logged 404s.
  - `/api/failures` + `/api/audit` return newest-first JSONL tails (500/200) **plus `total`**, so the badge reads "500/2,377" rather than passing the cap off as everything.
  - `/api/limits` persists to `ui.json → limits`, and `src/Bootstrap.php` now applies that override ahead of `RATE_LIMIT_*`, so the Management "Save" actually reaches the next ingest (it was a no-op). Verified: POST `150/120` → `ui.json` → GET `source: override` → audit `limits.update` → reset to the 100/60 default.
  - `/api/restore` is restricted to `.gz` archives already listed in `storage/backups/` (`basename` + regex + `is_file`): traversal, missing files, sub-paths and non-`.gz` names are all rejected with 400. **Not executed** — it is destructive (`restore … --drop`).
  - The backup glob widened to `*.gz`, so manual snapshots (`pre-rebuild-*.gz`) are listed too.
- [x] **`/api/sample` never matched a document.** It filtered on `score`, but merged records carry `confidence` (0-100) — so the spot-check sampler returned `{sample:null}` at every min ≥ 0 and the Quality page could only say "No organized records found". This is why `_labels` had never received a row and every `ml:export-pairs` run silently fell back to `organized` ground truth. It now matches `confidence` (overridable with `field=`, validated against the `/api/histogram` set).
- [x] **Labelling loop verified end to end — first human labels.** draw → "Looks right" → 3 pairs in `_labels` (`k/4332 × mal/5909`, `× t/56419`, `× al/5909`, `source=human`, `label=1`); `/api/labels` reports total 3 / positives 3 / human 3; `ml:export-pairs` reads them via `LabelStore::forBase('k','4332')`. The merge checks out: kitsu 4332, myanimelist 5909 and anilist 5909 are all "Seitokai no Ichizon" (`hiyori_id` 4095). Verdicts now only count in the session feed when a pair was really stored, and single-source draws disable the buttons and say why.
- [x] **Verdicts are per pair, not per document.** The panel asked for one verdict on the whole record and then wrote it against *every* cross-reference in it, so a document holding one wrong reference beside three correct ones recorded four labels that contradicted the evidence — a review pass could teach the trainer that correct merges are wrong. `/api/sample` now resolves each reference to the title its source holds, the panel lists one row per reference, the first reference is the base, and each other reference carries its own ✓ / ✗. "All look right" records the positive verdicts in one click. A reference whose source is not in the local database says "no data" and gets no controls, and the base is chosen from references that do resolve, so a draw cannot bait a verdict the reviewer could not reach.
- [x] **First reviewer label set (2026-10-16).** 30 documents sampled at `min=0`, 57 judgements (42 agree / 15 disagree) stored in both directions under `source=agent-review`; the sheet and its caveats live in `docs/labels/` and `scripts/apply-label-review.sh` re-applies it. The pass found 15 false merges, all scored **95.86**: that is `72.10 + 0.9 × 26.4` — a synonym-overlap bonus on metadata-identical pairs whose titles have zero similarity (`titleSimilarity = 0.0`, `isTitleRejected() = true` today). They are stale documents from before the blank-synonym drop and the title floor, and `metadata_fuzzy` as shipped rejects every one of them.
- [x] **Stale telemetry (systemic).** The service worker answered `/api/*` GETs with stale-while-revalidate, so the first load of every page rendered the *previous* response. Polling surfaces hid it; one-shot reads (`/api/sample`, `/api/failures`, `/api/benchmark`) kept a stale body for the whole session. `/api/*` and `/metrics` are now **network-first** with the cache used only as an offline fallback; cache version `hiyori-v4`.
- [x] **Job staleness.** A `running` ingest state that stopped reporting is now `stale` (`jobPhase`, 90s), so dead processes no longer read as live: their frozen speed/ETA is blanked and the "ingest rate" KPI only sums live jobs. Derived on read, since a killed ingest cannot write a terminal phase.
- [x] **Chart / layout correctness.** `SeriesChartCard` put `<Area>` inside `<LineChart>`, so the ingestion-rate chart drew nothing (now `ComposedChart`); x-axis labels follow the real sample cadence and MAX matches the largest selectable window. The pipeline strip reports `sources → merged` instead of `totalDocs - violations` (an arithmetic no-op that read as 125K → 125K). The merged-score histogram y-axis is no longer clipped. Validation tables truncate long titles instead of painting over the Errors column. Coverage views share one collection list (no derived `organized*`, no `_*`), fixing the badge/footer mismatch, and the `_counters`/`_migrations` bookkeeping collections start hidden.
- [x] **Honest benchmark panel.** `/api/benchmark` no longer merges a hardcoded seed over the benchmark log — the log stores `total`, which never mapped to the seeded `merges`, so every strategy displayed a fabricated merge count. Only recorded rows are returned now, labelled with their source log and date, pointing at `docs/combiner-strategy-benchmark.md` for the full comparison. The ML gate badge spells out its margin ("+0.107 vs baseline") instead of a bare number.
- [x] **Memory gauge.** `HealthCheck::checkMemory()` reported PHP's per-request footprint (~2 MB of a 512 MB limit) to the Memory gauge — a permanent 0% and an empty ring. It now reports host memory from `/proc/meminfo`, matching `checkDiskSpace()`'s host scope; PHP's own figures stay under `php_*`.

- [x] **Rejection buffer shipped (2026-10-17).** `RejectionStore` over `pair_rejections` (unique on the full pair, multiclass `approve`/`reject`/`unsure`), `php hiyori rejections add|list|stats|promote`, `GET/POST /api/rejections` + `POST /api/rejections/promote`, and a Quality-page buffer card with pending/promoted counts and a Promote button. The combiner populates it opt-in via `combine --rejection-buffer` (`rejection_buffer` config): a title-floor rejection whose metadata score still clears `rejection_buffer_floor` (default: the combine threshold) is written to the buffer as a `combiner`-sourced `reject`, so the queue fills with the borderline false-negatives a reviewer most needs to see. `promote()` copies approve→1 / reject→0 into `_labels` and skips `unsure`, so an ambiguous verdict never reaches the trainer. `schema/hiyori.schema.json` now also declares the opt-in rich fields (`tags`/`genres`/`synopsis`/`score`/`studios`) and the merge/ML fields (`confidence`, `ml_score`, `ml_model`, `ml_model_version`, `ml_decision`, `ml_decided_at`) that the exporter toggles and `metadata_ml` already emit. Three stale checkboxes (Explorer ML columns, Quality histograms, `/api/histogram?field=`) and the manami-vs-LFS "(done)" box are ticked — all were already shipped.

## Security notes (2026-10-15)

- **Dependabot high × 7 (`web/`) — accepted, no upstream fix.** `npm audit` reports 7 high advisories, all one chain: `shadcn@4.21.4` → `fast-glob` → `micromatch` → `braces@3.0.3` (GHSA-vfj7-8cjw-p6xm, stack-exhaustion DoS via deeply nested patterns). `braces@3.0.3` is the latest published release and is *still* in the vulnerable range, so there is nothing to bump to; npm's only suggested "fix" is a semver-major downgrade to `shadcn@1.0.0`. The package is build-time only (the `shadcn/tailwind.css` import is compiled into `web/dist` by Vite, so nothing ships to the browser) and the affected glob code only ever sees local file paths. Revisit when upstream publishes `braces > 3.0.3` or `shadcn` drops `fast-glob`.
- `composer audit`: no advisories.
