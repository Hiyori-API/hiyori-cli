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
- [ ] Write the manami-style vs LFS vs trunk-only vs release+LFS comparison into the design doc (done)

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
- [ ] Model selection + config: MiniLM-L12 / LaBSE / MiniLM-L6 (`ML_MODEL`)
- [ ] `embeddings` cache collection keyed by `(content_hash, model, model_version)`
- [ ] Recompute only when content hash or model/version changes
- [ ] Venv + pinned `requirements.txt` + smoke test

### 4.3 LLM-as-judge
- [ ] OpenAI/Anthropic judge for ambiguous band (70–85), JSON-schema output
- [ ] Invoke directly from PHP (Option B) with per-run cap + budget guard
- [ ] `llm_judgements` cache keyed by pair hash
- [ ] Telemetry for token/cost spend (`llm_tokens`, `llm_cost_usd`)

### 4.4 Decision model / classifier
- [ ] Human-in-the-loop label capture (`_labels`, extend `/api/sample` agree/disagree)
- [ ] LLM bootstrap of training labels
- [x] Train logistic regression (v2.0, pure PHP `ml:train`) / XGBoost (v2.1, pending) on feature JSONL
- [ ] Calibration (isotonic/Platt) so `ml_score` maps to 0–100 threshold
- [ ] DBSCAN title-clustering helper (optional, behind flag)
- [ ] Clef/JEB-style external scoring harness over the same feature JSONL

### 4.5 Strategy & storage
- [x] New strategy `semantic_rerank` as second-stage scorer on `metadata_fuzzy` candidates (local embeddings; LLM `metadata_ml` still pending)
- [x] Store `ml_score`, `ml_model`, `ml_model_version`, `ml_decision`, `ml_decided_at` on merges (`ml_reason` deferred)
- [ ] Migration v4 (`ml_fields_and_indexes`): indexes + `embeddings`/`llm_judgements`/`pair_rejections`/`_labels`
- [ ] Rejection buffer + multiclass labels (approve/reject/unsure)
- [x] Registration in `StrategyConfigurationFactory::AVAILABLE_STRATEGIES` (`metadata_ml`)

### 4.6 Dashboard
- [ ] Explorer: opt-in `confidence`/`ml_score`/`ml_model`/`ml_model_version`/`ml_decision` columns
- [ ] Quality page: metadata vs ML score histograms; `/api/histogram?field=`
- [ ] Threshold suggestions (merge count + precision/recall/F1 when labels exist)
- [ ] Rejection buffer review + promote-to-training
- [ ] Model/version filter for A/B review
- [ ] Settings → Combine/ML config (model, sidecar path, caps, budget, enable toggle)

### 4.7 Validation
- [ ] Offline A/B benchmark vs `metadata_fuzzy` (coverage, F1, duplicate keys = 0)
- [ ] Idempotency/content-hash regression test for re-runs
- [ ] Cost + latency documentation + shipping gate (F1 ≥ baseline +2 pts)


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
- [ ] Label capture via `/api/sample` agree/disagree for precision/recall tracking
- [ ] Release gate: default switch still pending — `combine:benchmark` reports the F1 comparison; agree the margin + confirm duplicate organized keys = 0 first
