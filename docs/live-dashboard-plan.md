# Hiyori CLI — Live Dashboard Plan

**Goal:** a live web dashboard to observe the ingestion → validation → combine pipeline in real time, and to monitor the health of the pipeline and the resulting dataset.

**Constraint / fit:** this is a headless PHP CLI + MongoDB project. The dashboard must require **no new heavy infrastructure** (no Node server, no Redis) — a single PHP process serving JSON + a single-page frontend, reading live state from MongoDB (authoritative) and a small telemetry state file written by the pipeline.

---

## 1. Architecture

```
                 ┌──────────────────────────────┐
 ingest/combine  │  (existing CLI processes)    │
   jobs  ───────►│  write telemetry state file   │  storage/state/ingest-<src>.json
                 │  + MongoDB collections        │
                 └──────────────┬───────────────┘
                                │
                        ┌───────▼────────┐
    Browser ──HTTP/SSE──│  dashboard:serve │  PHP built-in server (php -S, port 8080)
                        │   (router.php)   │
                        └───────┬─────────┘
                                │ reads
        ┌───────────┬───────────┼────────────┬───────────────┐
        ▼           ▼           ▼            ▼               ▼
   Mongo counts  telemetry   HealthCheck   MetricsCollector  Validator
   (counts,cursor)(jobs,rate) (APIs,ping)  (Prometheus)      (violations)
```

- **Backend:** a new `dashboard:serve` command that starts PHP's built-in web server (`php -S 0.0.0.0:8080 public/index.php`) — zero extra dependencies, daemonised appropriately (systemd unit or `--daemon`).
- **Frontend:** a single `dashboard.html` (vanilla JS, hand-rolled canvas sparklines — no CDN dependency, works air-gapped).
- **Live mechanism:** primary = **polling `/api/status` every 3 s** (cheap Mongo `countDocuments` + file reads). Optional **SSE** (`/api/events`) for push. Polling is the default because it is robust and trivially testable.

---

## 2. HTTP endpoints (served by the dashboard process)

| Method/Path | Purpose | Source |
|---|---|---|
| `GET /` | Dashboard page (html) | static asset |
| `GET /api/status` | Full JSON snapshot (all panels) | aggregate |
| `GET /api/counts` | Per-collection doc counts + deltas | Mongo |
| `GET /api/jobs` | Ingestion/combine jobs (source, mode, processed, speed, ETA, errors, circuit state) | telemetry state files |
| `GET /api/health` | External API latency/status, MongoDB ping, disk/mem | `HealthCheck` service |
| `GET /api/metrics` | Prometheus text | `MetricsCollector` |
| `GET /api/events` | SSE stream of `/api/status` snapshots | push |
| `GET /metrics` | Prometheus scrape endpoint | as above |
| `GET /api/validate` | Recent validation violations + per-collection invalid counts | `Validator` + state |

---

## 3. Live data sources (what powers each panel)

1. **MongoDB counts** — `countDocuments()` per collection (`myanimelist`, `kitsu`, `anilist`, `*_manga`, `organized`). Authoritative, updates as ingests insert. Deltas between polls → **growth rate**.
2. **Ingestion telemetry** — the pipeline currently writes only logs + the (MAL-only) resume file. **Plan:** add a tiny `Telemetry` writer (`Storage/State/Telemetry.php`) that ingestion/combine loops call every N entries: `{source, started_at, processed, total, per_second, last_entry, errors, updated_at, phase}` → `storage/state/ingest-<source>.json`. Also record combine runs (`storage/state/combine-latest.json`). This gives jobs/rate/ETA/errors with near-zero overhead.
3. **HealthCheck** — reuse the existing service (Mongo ping, anilist/kitsu/mal latency, disk, memory, rate-limiter states).
4. **MetricsCollector** — reuse existing Prometheus counters/histograms; expose via `/metrics`.
5. **Validator** — surface the `[validator] ...` violations (currently `error_log`) by also appending them to `storage/state/validation-log.jsonl` so the dashboard can show a live violation feed.

---

## 4. Dashboard UI panels

| # | Panel | Content | Live source |
|---|---|---|---|
| 1 | **Pipeline jobs** | rows per source: status (running/idle/done), mode (initial/update), processed/total, speed (entries/s), ETA, last error, circuit-breaker state badge | telemetry + rate limiter |
| 2 | **Ingestion rates** | sparkline (entries/s, last 5 min) per active source | telemetry history |
| 3 | **Dataset overview** | counts per collection w/ growth bar vs snapshot baseline; manga vs anime split | Mongo counts |
| 4 | **API health** | per-API latency + status dot, Mongo ping, disk/mem gauges | HealthCheck |
| 5 | **Rate limiter / circuit breakers** | tokens available, open/half-open/closed per source | RateLimiter stats |
| 6 | **Validation feed** | recent violations (field, message, count) + invalid-docs per collection | validation log |
| 7 | **Combine / merge** | last strategy run: strategy, merges, threshold, confidence, organized coverage % | combine telemetry |
| 8 | **Prometheus metrics** | counters/histograms table + link to `/metrics` | MetricsCollector |

Layout: header (overall health dot + uptime) → jobs + rates (top row) → dataset + health (mid) → validation + combine + metrics (bottom). Dark theme. Auto-refresh toggle; 3 s default.

---

## 5. Implementation plan (phases)

### Phase 1 — Backend + telemetry (core)
1. `src/Service/Telemetry.php` — small pure-PHP writer/reader (atomic `file_put_contents` with lock; JSON state).
2. Instrument `Ingestion` (`start()` → wrap loop) or each `Source\*Ingestion` to update telemetry every N entries (also on error). Instrument `CombinerCommand`/strategies for combine telemetry.
3. Route validation violations → `validation-log.jsonl` (in addition to `error_log`).
4. `src/Commands/DashboardCommand.php` (`dashboard:serve`) + `public/index.php` router:
   - `/api/status` aggregates counts + telemetry + health + metrics + validation
   - `/api/events` SSE wrapper
   - `/metrics` returns `MetricsCollector::render()`
   - static route serves `dashboard.html`
   - basic auth via optional `DASHBOARD_TOKEN` query/header (403 otherwise)
5. Add `dashboard:alle` alias? (`dashboard:serve` only)

### Phase 2 — Frontend
6. `public/dashboard.html` — single file: fetch `/api/status` on interval, render panels; canvas sparklines (no libs); auto-refresh + pause; error/empty states; filters (source selector, time range).
7. Basic a11y + responsive CSS.

### Phase 3 — Hardening & deploy
8. `dashboard:serve --daemon` / systemd unit example (like `schedule --install` prints units). Include in `docker-compose.yml` as an optional `dashboard` service (same container, `php -S 0.0.0.0:8080`).
9. Tests: 
   - unit: `Telemetry` read/write/lock, router route dispatch, JSON shapes
   - integration: live `dashboard:serve` hit `/api/status` and `/metrics` and assert 200 + sane payload (against running Mongo)
10. Coverage targets, CS, PHPStan (level 5) all green; docs snippet in README.

---

## 6. Key decisions & rationale

- **PHP built-in server over ReactPHP/Swoole** — zero new deps, adequate for a local ops dashboard; the CLI already runs on the box.
- **Polling over websockets** — counts are the ground truth and update seconds apart; polling is simpler, resilient, and testable. SSE offered as opt-in.
- **Telemetry files over parsing logs** — structured JSON beats scraping progress-bar ASCII; also fixes the gap that only MAL has a resume file today.
- **No CDN in frontend** — canvas sparklines hand-rolled so the dashboard works without internet (the box is often offline/limited).
- **Reuse existing services** (`HealthCheck`, `MetricsCollector`, `Validator`, `RateLimiter`) — no duplicate logic; the dashboard is a *view* over already-built telemetry.
- **Security**: local-only by default; optional `DASHBOARD_TOKEN`; bind tip to 127.0.0.1 unless overridden.

---

## 7. File layout (new)

```
src/Service/Telemetry.php
src/Commands/DashboardCommand.php
src/Server/Router.php            (route dispatch)
src/Server/Response.php          (JSON/HTML/SSE helpers)
public/index.php                 (front controller for php -S)
public/dashboard.html            (single-file frontend)
storage/state/ingest-<src>.json  (telemetry, gitignored)
storage/state/combine-latest.json
storage/state/validation-log.jsonl
docs/live-dashboard-plan.md      (this plan)
```

---

## 8. Open questions for you
1. Port (default **8080**) and bind (127.0.0.1 vs 0.0.0.0 for the compose service)?
2. Do you want the **manga collections** tracked on the dashboard from day one, or focus on anime + organized?
3. Permission model: none / token / username+password?
4. Build **now** (Phases 1–2) or wait until the fresh ingest run completes so we can demo with real numbers?
