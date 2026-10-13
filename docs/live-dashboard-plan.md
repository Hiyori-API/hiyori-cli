# Live Dashboard — Plan (shipped, superseded)

> [!NOTE]
> **Status: fully implemented.** The original plan below shipped and evolved;
> the canonical documentation now lives in:
> - the readme — [Live dashboard](../readme.md#live-dashboard) (pages, endpoints,
>   telemetry files, PWA, webhooks) and [Commands](../readme.md#commands)
>   (`dashboard:serve`);
> - [dashboard-roadmap.md](dashboard-roadmap.md) — the “Live dashboard — plan →
>   shipped” section and the feature trackers (PWA + real-time, Settings →
>   Environment manager, Explorer backend wave, Dashboard v2).

## Original plan (summary)

**Goal:** a live web dashboard to observe the ingestion → validation → combine
pipeline in real time and monitor the health of the pipeline and dataset.

**Constraint:** the project is a headless PHP CLI + MongoDB. The dashboard
requires no new heavy infrastructure — a single PHP process serving JSON + a
single-page frontend, reading live state from MongoDB (authoritative) and small
telemetry state files written by the pipeline.

**Key decisions (all kept in the shipped implementation):**

- **PHP built-in server over ReactPHP/Swoole** — zero new dependencies,
  adequate for a local ops dashboard.
- **Polling over websockets** — counts are ground truth and update seconds
  apart; polling is simpler, resilient and testable. SSE (`/api/events`) is
  offered as an opt-in for `realtime` mode.
- **Telemetry files over parsing logs** — structured JSON under
  `storage/state/` beats scraping progress-bar ASCII; also fixed the gap where
  only MAL had a resume file.
- **Reuse existing services** (`HealthCheck`, `MetricsCollector`, `Validator`,
  `RateLimiter`) — the dashboard is a *view* over already-built telemetry.
- **Security** — local-only by default, optional `DASHBOARD_TOKEN`, bind to
  `127.0.0.1` unless overridden.

**Divergence from the plan:** the frontend shipped as a React 19 + shadcn/ui
SPA (Vite, TanStack DataTable/Virtual, Recharts) rather than a single-file
vanilla-JS `dashboard.html`, and the endpoint set grew well beyond the original
8 routes. Page set: Dashboard, Explorer, Management, Quality & Analysis, Logs,
Settings (incl. Environment manager).