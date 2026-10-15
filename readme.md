# Hiyori CLI

[![PHP](https://img.shields.io/badge/PHP-8.3-blue.svg?style=flat)](https://www.php.net/) [![Discord Server](https://img.shields.io/discord/460491088004907029.svg?style=flat&logo=discord)](http://discord.jikan.moe)

[![CI](https://github.com/Hiyori-API/hiyori-cli/actions/workflows/ci.yml/badge.svg)](https://github.com/Hiyori-API/hiyori-cli/actions/workflows/ci.yml) [![Tests](https://img.shields.io/badge/unit-166%20tests%20%2F%20480%20assertions-blue)]() [![PHPStan](https://img.shields.io/badge/PHPStan-level%205-purple.svg)]() [![License](https://img.shields.io/badge/license-MIT-blue.svg)]()

Hiyori CLI builds and maintains [HiyoriDB](https://github.com/Hiyori-API/HiyoriDB) — a relational anime & manga database. It ingests from **AniList, Kitsu and MyAnimeList** (anime and manga), merges everything into a normalized schema, and ships a live React dashboard for exploring, managing and analyzing the result.

> [!NOTE]
> Under active development. The CLI pipeline and dashboard are feature-complete for the current roadmap; expect continued hardening. See [docs/](docs/dashboard-roadmap.md) for the tracked status.

---

## Features

- **Multi-source ingestion** — MyAnimeList (Official API v2), AniList and Kitsu, for both **anime** and **manga** collections, with per-source rate limiting, circuit breakers and resume support.
- **Schema normalization** — source-specific values (statuses, types, seasons) are mapped onto the shared [Hiyori Schema](#hiyori-schema) via enums.
- **Relational mapping** — known source cross-references are parsed into `reference_ids` (`mal`, `al`, `k`, …); supported references are resolved to per-source IDs.
- **Combiner with 7 strategies** — `relational_mapping`, `relational_fuzzy`, **`metadata_fuzzy`** (default, weightage-tuned), `graph_merge`, `fellegi_sunter`, `semantic_rerank` (local embeddings) and `metadata_ml` (trained logistic second-stage scorer).
- **Live dashboard** — a React + shadcn/ui SPA (PHP `php -S` backend) with dedicated **Explorer, Management, Quality & Analysis, Logs and Settings** pages, realtime SSE, PWA caching and an **Environment manager**.
- **Backups & restore** — `mongodump`/`mongorestore` based, scriptable and dashboard-triggered.
- **Scheduler** — run ingestion on a schedule via systemd timers/cron (`schedule --install`).
- **Utilities** — validation against the schema, sweeping (orphans/duplicates/incomplete), weightage analysis, export (JSON/JSONL/CSV), Prometheus metrics and health checks.
- **Docker** — `docker-compose.yml` for MongoDB + the CLI.

---

## Quick start

```bash
# 1. Install dependencies
composer install

# 2. Configure the environment
cp .env.example .env
#    → set MONGODB_URL and (for MAL ingestion) MAL_CLIENT_ID / MAL_CLIENT_SECRET

# 3. Ingest a source (anime by default)
php hiyori ingest anilist

# 4. Combine ingested data into the `organized` collection
php hiyori combine anilist

# 5. Open the live dashboard
php hiyori dashboard:serve                 # http://127.0.0.1:8080
php hiyori dashboard:serve --install       # or install a systemd user unit
```

See [Installation](#installation) for prerequisites and the [Commands](#commands) section for every available command.

---

## Installation

Prerequisites:

- **PHP 8.3+** (the version exercised in CI) with the `mongodb`, `mbstring`, `xml`, `curl` and `zip` extensions
- **MongoDB** (7.x) running locally or remotely
- **Composer**

```bash
git clone https://github.com/Hiyori-API/hiyori-cli.git
cd hiyori-cli
composer install
chmod +x hiyori          # optional — `php hiyori` works everywhere
cp .env.example .env     # then edit to taste
```

---

## Commands

The tool runs as `./hiyori`, `php hiyori` or `php src/run.php`.

| Command | Description |
|---|---|
| `ingest <source>` | Begin ingestion from a source (`myanimelist`, `anilist`, `kitsu`) |
| `ingest:all` | Run ingestion for all available sources sequentially |
| `combine <base>` | Merge all source datasets into one collection |
| `validate` | Validate existing data against the Hiyori schema |
| `sweep` | Clean up orphaned, duplicate or incomplete entries |
| `weightage <s1> <s2>` | Calculate ideal metadata-fuzzy weights between two sources |
| `ml:export-pairs` | Export labelled candidate pairs (JSONL) for ML training / benchmarking |
| `ml:train` | Train the `metadata_ml` logistic-regression scorer from exported pairs |
| `labels` | Add / inspect human pair labels (agree/disagree) that override the organized ground truth |
| `combine:benchmark` / `bench` | Offline precision/recall/F1 sweep of `metadata_fuzzy` vs `metadata_ml` from labelled pairs |
| `combine:dupes` / `dupes` | Detect duplicate reference keys in combined collections (release gate: `mal`/`al` must stay unique) |
| `references` | Return all unique reference IDs from the combined DB |
| `export` | Export data from sources or combined DB (profiles: per-entry-json, bulk-jsonl, tables-csv) |
| `backup` | Back up the database with `mongodump` |
| `restore <archive>` | Restore the database from an archive with `mongorestore` |
| `schedule` | Run ingestion on a schedule (systemd timers / cron) |
| `health` | Health check for services and dependencies |
| `metrics` | Expose Prometheus metrics |
| `dashboard:serve` | Serve the live dashboard (UI + API + SSE + `/metrics`) |
| `publish` / `db:push` | Build relational dump and push to HiyoriDB repository (`--verify`, `--max-changed`) |
| `release` / `db:release` | Build, verify and publish a compressed Tier B release (`.tar.zst` + sidecars + `SHA256SUMS.txt`) |
| `completion` | Dump a shell completion script |
| `hiyori-id` / `ids` | Report / backfill / verify Hiyori IDs (`--verify`, `--backfill`, `--json`) |

### Ingestion

```console
php hiyori ingest myanimelist --delay 1   # anime
php hiyori ingest anilist --delay 1
php hiyori ingest kitsu --delay 1

php hiyori ingest myanimelist_manga --delay 1   # manga
php hiyori ingest anilist_manga --delay 1
php hiyori ingest kitsu_manga --delay 1

php hiyori ingest:all                     # all sources, sequentially
```

| Option | Description |
|--------|-------------|
| `--delay`, `-d` | Delay between requests in seconds (default: `1`); keep ≥1s to respect source rate limits |
| `--update`, `-u` | Update existing entries instead of skipping |
| `--resume-page`, `-r` | Resume from a specific page number |

> [!NOTE]
> 1. Spoiler-tagged metadata (general or media) is not ingested.
> 2. AniList caps reported totals at 5,000 per query. Ingestion works around the cap by chunking the catalog by `seasonYear` (1950 → current year + one no-year pass); manga continues past the cap via pagination on short/empty pages.

### Combining

Run the combiner **after** ingestion of the sources you want merged has completed.

```console
php hiyori combine {base} [--strategy {strategy}]
```

`{base}` is the source dataset to merge from (e.g. `myanimelist`, `anilist`, `kitsu_manga`). Sources are merged **into** the `organized` collection by default.

| Option | Description |
|--------|-------------|
| `--strategy`, `-s` | One of the six strategies below. **Default: `metadata_fuzzy`** |
| `--threshold`, `-t` | `metadata_fuzzy` score threshold, 0–100 (default: `70`) |
| `--weights`, `-w` | `metadata_fuzzy` field weights as JSON, e.g. `{"title":25,"tags":20}` |
| `--into` | Collection to merge into (e.g. `organized_manga`). Default: `organized` |
| `--export` | After combine, run exporter with profile (`per-entry-json`, `bulk-jsonl`, `tables-csv`, or `none`). Default: `combiner.auto_export` config value |
| `--skip-migrations` | Bypass the pending-migration gate (not recommended) |

#### Available strategies

- **`relational_mapping`** — merges entries whose source IDs match directly across the available datasets.
- **`relational_fuzzy`** — cross-checks every `reference_ids` value across datasets to find the same entry in another source.
- **`metadata_fuzzy`** *(default)* — compares entry metadata, weighting each field (Levenshtein for titles, Jaccard for tags). Entries are merged when the weighted score exceeds the threshold. The built-in weights were tuned via `hiyori weightage` on ground-truth MAL↔AniList pairs:

- **`graph_merge`** — transitive connected-component merge over low-confidence candidates (catches A↔B↔C pairs that `metadata_fuzzy` misses).
- **`fellegi_sunter`** — probabilistic record linkage with configurable `m`/`u` field probabilities.
- **`semantic_rerank`** — `metadata_fuzzy` candidates re-ranked with local embeddings (`ML_EMBED_COMMAND` sidecar; deterministic n-gram fallback).
- **`metadata_ml`** — `metadata_fuzzy` candidate chain + anchor rejection, scored by a logistic-regression model trained on labelled pairs (`ml:export-pairs` → `ml:train`). Merges carry `ml_score`/`ml_model`/`ml_model_version`/`ml_decision`/`ml_decided_at`. Requires a trained model (hard error otherwise).
  | field | weight | | field | weight |
  |---|---|---|---|---|
  | `title` | 26.4 | | `status` | 0.6 |
  | `episodes` | 26.2 | | `season` | 16.4 |
  | `year` | 23.2 | | `type` | 5.7 |
  | `tags` | 1.5 | | `synonyms` | 0 |

  Weights are normalized at runtime, so they need not sum to 100.

Example — build the manga collection separately from the anime merge:

```console
php hiyori combine anilist_manga --strategy metadata_fuzzy --into organized_manga
```

### Utilities

```console
# Validate existing data against the Hiyori schema
php hiyori validate [--source anilist|organized] [--limit N] [--json]

# Clean up orphaned / duplicate / incomplete entries
php hiyori sweep [--source myanimelist] [--dry-run] [--orphans|--duplicates|--incomplete|--all]

# Report all unique reference IDs from the combined DB
php hiyori references [--source mal] [--json]

# Tune metadata-fuzzy weights between two sources (feeds combine --weights)
php hiyori weightage myanimelist anilist [--sample 1000] [--json]

# Capture a human label for a candidate pair (overrides the organized ground truth).
# The dashboard's merge sampler posts these to POST /api/labels on agree/disagree.
php hiyori labels add --base-source mal --base-id 1 --candidate-source al --candidate-id 2 --label 1
php hiyori labels stats
php hiyori labels list --base-source mal --base-id 1

# Export labelled candidate pairs for ML (human labels win over the organized collection).
# --holdout writes a deterministic, group-safe -train/-holdout pair of files:
php hiyori ml:export-pairs --base myanimelist [--limit 500] [--negatives 3] [--sample random|first] [--holdout 30]

# Train the metadata_ml scorer from the newest exported pairs file
php hiyori ml:train [--in storage/ml/pairs-*.jsonl] [--epochs 400] [--threshold 0.5]

# Combine with the ML scorer (needs the model file; tune via --config JSON)
php hiyori combine myanimelist --strategy metadata_ml --config '{"ml_threshold":0.6,"metadata_floor":55}'

# Benchmark scorers offline (no DB writes): F1 gate at 60/65/70/75/80/85/90.
# Honest gate: train on the -train file, benchmark on the untouched -holdout file.
php hiyori ml:train --in storage/ml/pairs-*-train.jsonl
# --margin sets the required F1 advantage (points) of metadata_ml over metadata_fuzzy;
# --strict exits non-zero when the gate is not met (for CI).
php hiyori combine:benchmark --in storage/ml/pairs-*-holdout.jsonl [--ml-model storage/state/ml-model.json] [--margin 2] [--strict] [--json]

# Release gate: no combined collection may repeat an identity key (mal/al).
# Reports every duplicated source key; franchise keys (k/cr/t/nf) are expected
# to repeat.
php hiyori combine:dupes [--collection organized] [--primary mal,al] [--json]

# Export data (organized by default) as JSON, JSONL or CSV
# Export combined DB with the configured profile (default per-entry-json)
php hiyori export [--profile per-entry-json|bulk-jsonl|tables-csv] [--dry-run] [--preview manga]

# Export a raw source as JSON/JSONL/CSV (legacy format)
php hiyori export organized|myanimelist|anilist|kitsu [--format json|jsonl|csv] [--output file] [--fields title,type,status] [--limit 100] [--pretty]

# Back up / restore the database (mongodump / mongorestore)
php hiyori backup [--output storage/backups/hiyori-<ts>.gz] [--collections a,b,c] [--gzip]
php hiyori restore storage/backups/hiyori-<ts>.gz [--drop]

# Health check for MongoDB, external APIs, rate limiter, disk and memory
php hiyori health [--json] [--watch] [--interval 30]

# Render Prometheus metrics as text (one-shot, to stdout; the dashboard's
# /metrics endpoint serves them over HTTP for scraping)
php hiyori metrics
```

---

## Hiyori Schema

All sources are transformed into a common representation. Values with different spellings across sources (e.g. MAL `Finished Airing` vs AniList `Finished`) are normalized through Hiyori's enums.

### Anime

| Property        | Data Type          | Remarks                                                            | Nullable |
|-----------------|--------------------|--------------------------------------------------------------------|----------|
| `title`         | _String_           | Entry's main title                                                 | ❌        |
| `synonyms`      | _Array of String_  | All other titles (combined from sources)                           | ❌        |
| `type`          | _String_           | Entry type [`tv`, `movie`, `ova`, `ona`, `special`, `music`]       | ✅        |
| `episodes`      | _Integer_          | Number of episodes (if any mentioned)                              | ✅        |
| `status`        | _String_           | Status of entry [`finished`, `airing`, `to_be_aired`, `cancelled`] | ✅        |
| `season`        | _Nullable String_  | Release Season [`winter`, `summer`, `spring`, `fall`]              | ✅        |
| `year`          | _Nullable Integer_ | Release Year                                                       | ✅        |
| `images`        | _Array of String_  | Default Image URLs (combined from sources)                         | ❌        |
| `reference_ids` | _Object_           | Parsed IDs from Supported References                               | ❌        |
| `references`    | _Array of String_  | Reference URLs (combined from sources)                             | ❌        |
| `tags`          | _Array of String_  | Genres/Tags (combined from sources)                                | ❌        |

### Manga

| Property        | Data Type          | Remarks                                                            | Nullable |
|-----------------|--------------------|--------------------------------------------------------------------|----------|
| `title`         | _String_           | Entry's main title                                                 | ❌        |
| `synonyms`      | _Array of String_  | All other titles (combined from sources)                           | ❌        |
| `type`          | _String_           | Entry type [`manga`, `novel`, `light_novel`, `one_shot`, `doujinshi`, `manhwa`, `manhua`, `oel`] | ✅ |
| `chapters`      | _Integer_          | Number of chapters (if any mentioned)                              | ✅        |
| `volumes`       | _Integer_          | Number of volumes (if any mentioned)                               | ✅        |
| `status`        | _String_           | Status of entry [`finished`, `publishing`, `not_yet_published`, `on_hiatus`, `discontinued`] | ✅ |
| `year`          | _Nullable Integer_ | Release Year                                                       | ✅        |
| `images`        | _Array of String_  | Default Image URLs (combined from sources)                         | ❌        |
| `reference_ids` | _Object_           | Parsed IDs from Supported References                               | ❌        |
| `references`    | _Array of String_  | Reference URLs (combined from sources)                             | ❌        |
| `tags`          | _Array of String_  | Genres/Tags (combined from sources)                                | ❌        |

### Supported references

The following URL types are parsed when detected in an entry's `references`, and stored in `reference_ids` under the shorthand shown.

| Reference | Shorthand |
|-----------|-----------|
| [Amazon Prime](src/Models/Common/Identifiers/AmazonPrime.php) | `ap`  |
| [AniDB](src/Models/Common/Identifiers/AniDB.php) | `adb` |
| [AniList](src/Models/Common/Identifiers/AniList.php) | `al`  |
| [AnimeNewsNetwork](src/Models/Common/Identifiers/AnimeNewsNetwork.php) | `ann` |
| [Bangumi](src/Models/Common/Identifiers/Bangumi.php) | `b`   |
| [Crunchyroll](src/Models/Common/Identifiers/Crunchyroll.php) | `cr` |
| [Funimation](src/Models/Common/Identifiers/Funimation.php) | `fm` |
| [Kitsu](src/Models/Common/Identifiers/Kitsu.php) | `k`   |
| [MyAnimeList](src/Models/Common/Identifiers/MyAnimeList.php) | `mal` |
| [Netflix](src/Models/Common/Identifiers/Netflix.php) | `nf`  |
| [Syoboi](src/Models/Common/Identifiers/Syoboi.php) | `sb`  |
| [Trakt](src/Models/Common/Identifiers/Trakt.php) | `t`   |
| [TubiTV](src/Models/Common/Identifiers/TubiTV.php) | `ttv` |
| [TVTokyo](src/Models/Common/Identifiers/TVTokyo.php) | `tvt` |
| [Twitter](src/Models/Common/Identifiers/Twitter.php) | `tx`  |
| [Wikipedia English](src/Models/Common/Identifiers/WikipediaEN.php) | `wpen` |
| [Wikipedia Japanese](src/Models/Common/Identifiers/WikipediaJA.php) | `wpjp` |
| [YouTube](src/Models/Common/Identifiers/YouTube.php) | `yt`   |

---

## Configuration

### Environment variables (`.env`)

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_ENV` | Environment (`prod` / `dev` / `test`) | Yes |
| `APP_DEBUG` | Debug mode (`true` / `false`) | Yes |
| `APP_VERSION` | Application version | Yes |
| `MONGODB_URL` | MongoDB connection string | Yes |
| `MAL_CLIENT_ID` / `MAL_CLIENT_SECRET` | MyAnimeList API v2 credentials | For MAL ingestion |
| `DASHBOARD_TOKEN` | Optional token protecting `/api/*` (client passes `?token=` or `X-Dashboard-Token`) | No |
| `WEBHOOK_URL` | Optional webhook URL for anomaly alerts | No |
| `SCHEDULE_SOURCES` | Sources for `schedule` / dashboard scheduler (default: `anilist,kitsu,myanimelist`) | No |
| `SCHEDULE_INTERVAL` | Scheduler interval in seconds (default: `86400`) | No |
| `RATE_LIMIT_MAX` | Max requests per window (default: `100`) | No |
| `RATE_LIMIT_WINDOW` | Rate-limit window in seconds (default: `60`) | No |
| `HTTP_CACHE` | Enable HTTP caching (`true` / `false`) | No |
| `HTTP_CACHE_PATH` | Cache directory path | No |
| `HTTP_CACHE_TTL` | Cache TTL in seconds | No |
| `PROGRESSBAR_FORMAT` | Progress bar format string | No |
| `PROGRESSBAR_REDRAW_FREQ` | Progress bar redraw frequency | No |

### Rate limiting

Each source (`myanimelist`, `anilist`, `kitsu`, `myanimelist_manga`, `anilist_manga`, `kitsu_manga`) has its own token bucket with configurable max requests and time window. A circuit breaker opens when the failure rate exceeds 50%.

---

## Live dashboard

A live ops dashboard for the ingestion → validation → combine pipeline: React 19 + shadcn/ui frontend (Vite, TanStack DataTable/Virtual, Recharts) served by PHP's built-in server, with the SPA prebuilt in `web/dist` (committed — no build step needed to run; `cd web && npm run build` after frontend changes).

```console
# Serve the dashboard (UI + API + SSE + Prometheus on one port)
php hiyori dashboard:serve --port 8080

# Optional access token
php hiyori dashboard:serve --port 8080 --token mysecret

# Install a systemd user unit instead of running in the foreground
php hiyori dashboard:serve --install
```

Open <http://127.0.0.1:8080>. Bind to another host with `--host` (default `127.0.0.1`).

### Pages

| Page | Purpose |
|------|---------|
| **Dashboard** | Live telemetry hub: KPIs, per-source fleet strip, rate & composition charts, dataset doughnut, health gauges, pipeline funnel, alert strip |
| **Explorer** | MongoDB browser: collection tabs, paginated/sortable table (TanStack), search, prebuilt filters (`no_MAL` / dupes / orphans), facets & numeric ranges, column picker, row JSON drawer, single/bulk edit + delete with undo, CSV import/export, ref resolver, two-doc diff, saved queries |
| **Management** | Source start/pause/resume/retry, scheduler config + run-now, backup list + download + restore, circuit breaker & tokens, rate-limiter overrides, validation-failure retry queue, alert acknowledgements, audit log |
| **Quality & Analysis** | Coverage matrix + missing-critical-refs, strategy benchmark, threshold-sensitivity curve, confidence distribution, merge sampler, validation trend, weightage explorer (live sliders → `combine --weights`), merged-dataset export |
| **Logs** | Slow-request table, latency buckets, validation log (source-filtered), ingestion run history, cache panel |
| **Settings** | Refresh interval, theme/accent/density, timezone, token rotate, webhook URL + test delivery, collection/widget visibility, settings import/export/reset, and the **Environment manager** (browse/edit `.env` values, secrets masked) |

The top bar has a **Live / Live off** toggle: `realtime` mode pushes full status snapshots over SSE (`/api/events`) with instant count/job updates, and falls back to polling otherwise. The dashboard is installable as a **PWA** (manifest + service worker) and serves cached data offline.

### API endpoints

| Path | Purpose |
|------|---------|
| `/` | Dashboard SPA (from `web/dist`) |
| `/api/status` | Aggregated snapshot (counts, jobs, db, controls, cache, health, validation, coverage, alerts, slow, schedule) |
| `/api/counts` | Per-collection counts |
| `/api/db` | DB size / index / docs stats |
| `/api/jobs` | Ingestion telemetry (per source: processed/saved/skipped/errors/speed/ETA) |
| `/api/health` | External API latency + Mongo + disk/mem |
| `/api/validate` | Recent validation violations |
| `/api/coverage` | Reference-shorthand coverage matrix per collection |
| `/api/benchmark` | Seeded 3-strategy comparison + latest combine |
| `/api/alerts` | Anomaly evaluation (circuit open, low tokens, API down); posts to `WEBHOOK_URL` when set |
| `/api/sample` | Random doc for the merge sampler |
| `/api/slow` | Request latency extremes (>2s) |
| `/api/schedule` | Scheduled-run info |
| `/api/histogram` | Score/confidence histogram (`?collection=`, 10 bins) |
| `/api/meta` | Collection counts, fields, per-field missingness |
| `/api/explore` | Explorer queries (search, sort, facets, numeric ranges) |
| `/api/explore/update` | Inline single-field `$set` / `$unset` |
| `/api/explore/bulk` | Bulk set / delete / soft-delete (snapshotted for undo) |
| `/api/explore/undo` | List/restore undo batches |
| `/api/explore/import` | CSV upsert into a collection |
| `/api/env` | Environment manager: list (masked secrets), `GET ?reveal=KEY`, `POST` upsert/remove |
| `/api/settings` | Dashboard preferences (GET/POST, token rotate, webhook test) |
| `/api/backups` | Backup archive list |
| `/api/restore` | Trigger a restore from an archive |
| `/api/action?job=&cmd=pause\|resume\|retry` | Control jobs |
| `/api/backup?run=1` | Trigger async `mongodump` |
| `/api/events` | SSE stream of status snapshots |
| `/metrics` | Prometheus text |

### Telemetry & control files

All live state lives under `storage/state/` (gitignored):

- `ingest-<source>.json` — live ingestion status (written by the ingest loops)
- `control-<source>.json` — `{"paused": true}` pauses that source (polled every second)
- `combine-latest.json` — last combine run summary
- `validation-log.jsonl` — validation violation feed
- `slow-requests.jsonl` — requests slower than 2s
- `audit.jsonl` — audit trail for mutating dashboard actions
- `ui.json` — dashboard settings prefs + rate-limiter overrides
- `api-outage.json`, `cache.json` — alert state / cache counters

### Webhook alerts

Set `WEBHOOK_URL` (and optionally `SCHEDULE_SOURCES`, `SCHEDULE_INTERVAL`) in `.env`; alerts are delivered once per identity per hour. The critical "external APIs unreachable" banner requires repeated unreachable polls (debounced), and 429/redirects are treated as degraded (reachable), never "unreachable".

---

## Architecture & conventions

```
src/
├── Config/            # Sources registry (source names, list endpoints, identifiers)
├── Requests/          # Per-source entry/request classes (AniList, Kitsu, MyAnimeList)
│                      #   + shared request facade (delay, rate limit, telemetry)
├── Models/            # Schema models (Common, Anime, Manga) + enums + Identifiers
├── Service/           # Domain services
│   ├── Ingestion/     #   ingest pipeline (per-source, telemetry-driven)
│   ├── Combiner/      #   merge strategies (relational_mapping / relational_fuzzy /
│   │                  #     metadata_fuzzy) + StrategyConfigurationFactory
│   ├── Database.php   #   Mongo access (counts, mergeIntoOrganized, cursors)
│   ├── Explorer.php   #   Explorer query/facet/CSV logic (DB-less)
│   ├── HealthCheck.php, MetricsCollector.php, RateLimiter.php
│   ├── Telemetry.php, Validator.php, Serializer.php
│   └── Logging.php, ConsoleFactory.php
├── Commands/          # Symfony Console commands — thin wrappers over services
├── Server/            # (reserved)
├── Hiyori.php         # Composition root wiring services together
├── Bootstrap.php
public/index.php       # php -S front controller: SPA + JSON API + SSE + /metrics
web/                   # React 19 + Vite + shadcn/ui + TanStack + Recharts dashboard SPA
storage/               # Runtime state (gitignored): state/ telemetry, backups/, logs/, cache/
tests/                 # PHPUnit — Unit (DB-less) + Integration suites
```

Conventions:

- **CLI commands are thin facades** — logic lives in `src/Service/*`; commands only parse input and wire services. Add behavior to a service first, not the command.
- **Sources are configuration** — a source's list endpoint, entry properties and identifiers are declared in `src/Config/Sources.php` and driven by `SourceConfigurationFactory`; ingestion code is source-agnostic.
- **The combiner is strategy-driven** — all strategies implement `CombinerStrategy`; the default (`metadata_fuzzy`) reads its weights/threshold from config, with the tuned weights embedded as the fallback.
- **State is explicit** — every live field (ingest progress, controls, validation, slow requests, audit) is a small JSON/JSONL file under `storage/state/`, written atomically by `Telemetry`. Mongo is the authoritative data store; state files are cheap, human-inspectable views used by the dashboard and scheduler.
- **One dashboard server** — `public/index.php` serves the SPA, the JSON API, the SSE stream and `/metrics` from a single `php -S` process; no Node server or Redis is required.

---

## Docker Deployment

```bash
# Build and start MongoDB + the CLI container
docker-compose up -d

# Run ingestion inside the container
docker-compose exec hiyori php hiyori ingest myanimelist --delay 1

# Health check
docker-compose exec hiyori php hiyori health --json
```

---

## Testing & Quality

```bash
composer test                    # full PHPUnit suite
composer test:unit               # Unit suite only
composer test:integration        # Integration suite (needs MongoDB)
composer lint                    # PHPStan, level 5
composer lint:strict             # PHPStan, level 8
composer cs                      # PHP-CS-Fixer dry-run
composer cs:fix                  # PHP-CS-Fixer fix
composer coverage                # PHPUnit with pcov coverage
composer check                   # cs + lint + test
```

CI (GitHub Actions) runs PHP lint, code style, PHPStan level 5, unit and integration tests against MongoDB 7 on PHP 8.3, and uploads a coverage report.

---

## Docs

- [docs/dashboard-roadmap.md](docs/dashboard-roadmap.md) — living feature tracker for the dashboard, explorer, combiner and manga work
- [docs/explorer-ux-plan.md](docs/explorer-ux-plan.md) — Explorer UX overhaul + TanStack DataTable plan
- [docs/live-dashboard-plan.md](docs/live-dashboard-plan.md) — original live-dashboard plan (shipped; kept for history)

## Roadmap

👉 [View Roadmap](https://github.com/orgs/Hiyori-API/projects/1)

## FAQ

<details>
<summary>Why does the Hiyori Schema consist of metadata?</summary>

When an entry from a source has no relational IDs or source-provided mapping, metadata is used to find the relation instead.
</details>

<details>
<summary>Will you add more metadata fields on request?</summary>

Metadata is only used to compute a relation score when no direct reference exists between entries. There are no plans to add more metadata unless it can produce a better relation score for unmapped entries.
</details>

---

> [!IMPORTANT]
> Hiyori is not affiliated with any of the data sources. You are responsible for your usage of this tool; please respect the terms and conditions set by the sources.