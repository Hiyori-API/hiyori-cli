# Ingestion audit (sources, speed, totals)

Audited: 2026-10-15

## 1. Process count observed

The historical "13 processes" were not Hiyori CLI ingests. They were mostly from an unrelated project
 (`jikan-rest` RoadRunner + indexer + phpunit). Active Hiyori-CLI runtime at this point is only the dashboard server on `127.0.0.1:8080`.
No `hiyori ingest` process is currently running; the `phase: running` telemetry is stale from previously stopped runs.

## 2. Persisted ingested counts

| Source             | DB count | State processed / saved | State total | Notes |
|--------------------|----------|------------------------|-------------|-------|
| AniList (anime)    | 15,015   | 6,350 / 1,662*        | 6,350       | *Telemetry total is only the last year-window, not cumulative. Actual DB DB count is the source of truth. |
| AniList (manga)    | 38,097   | 2,075 / 2,070          | 2,075       | Telemetry only last run/window; DB DB count is useful. |
| Kitsu (anime)      | 8,667    | 8,650 / 1,191          | 22,500      | Progress only ~38.5%. Kitsu meta total looks numeric and plausible. |
| Kitsu (manga)      | 7,560    | 1,600 / 1,527          | 63,218      | Progress only ~12%. 73 errors from date/synonym validation. |
| MyAnimeList anime  | 10,694   | 10,675 / 836           | 25,000**    | **Hard-coded 25,000 is not measured by the API. The endpoint yielded 10,694 records. |
| MyAnimeList manga  | 13,044   | 2,375 / 2,375          | 25,000**    | **Hard-coded 25,000. DB count indicates earlier runs/continuation. |

## 3. Why throughput is low

Per-entry API detail calls dominate the runtime:

- Kitsu anime: each item makes mappings + streaming links + genres + categories (4 HTTP calls) and `sleep(delay)` twice. At 1s delay and ~1 HTTP per call, effective throughput is around 0.45 docs/sec.
- Kitsu manga: same pattern, plus retry backoff and validation errors. Effective throughput reached ~0.08 docs/sec.
- MyAnimeList: each item makes one detail HTTP call via a retryable client, with 30s timeout and 1s delay before each page. Effective throughput around 0.56 docs/sec.
- AniList anime/manga: once each page is fetched (50 items/page) no per-item HTTP calls are made, so throughput improves once pages are warm.

Constraint notes:
- AniList pagination uses 50 items/page.
- Kitsu offset pagination is 20 items/page.
- MAL uses 500 items/page ranking, but pages after the first need a detail call for every item.
- Source API rate limits remain the real bottleneck; internal `--delay 1` is in addition to rate limiter behavior.

The current speed is not necessarily a bug for public APIs, but it does mean that a full fresh ingest takes many hours and that the state file can become stale if the process is interrupted.

## 4. Whether the totals are wrong/assumed

- **AniList anime/manga**: totals are not explicitly requested in ingestion, and pagination stops on a short page. The stored counts are therefore actual ingested universe *minus duplicate skips*. They can be compared against sourceide totals if those are fetched separately.
- **Kitsu anime**: `total=22,500`, from live endpoint. DB count at 8,667 is incomplete, not capped by `22,500`. The source can exceed the DB count.
- **Kitsu manga`: `total=63,218`, from live endpoint. DB count 7,560 is incomplete, not capped by `63,218`.
- **MyAnimeList anime/manga**: `fetchMeta` hard-codes `['total' => 25000]`. The official MAL ranking endpoint does not return a total in the visible branch of code; the code assumes 25,000 and loops to offset `25000`. The DB count 10,694 (anime) means the ingest actually stopped after that number of records, so **25,000 is likely an assumption and may overstate the accessible ranking universe.**
- Other `phase: running` entries in state files are stale; those ingestion jobs are no longer running as processes.

## 5. Practical improvements

1. Reduce Kitsu per-item detail calls:
   - Use GitHub API `include` parameters or request relation batching if the Kitsu endpoint supported it.
   - Cache mapping/genre responses if those responses are stable.
2. Remove the hard-coded MAL `25000`:
   - Make total discovery dynamic: stop on short/empty pages, not a fixed ceiling.
   - Follow `pagination.next` only while it exists.
   - Log the actual last offset as "source universe estimate".
3. Increase the rate-allowance only when appropriate:
   - The default `--delay 1` is enforced on top of rate limiter. Lower it only against scheduler JSON targets as owners control.
4. Resume every importer page-by-page:
   - Persist a resumption cursor (`last_offset`) in `storage/state/` while the job runs, and use `--resume-page`.
   - Existing DB's `exists()` check makes re-runs safe for inserts, but a failed slow ingestion still wastes time.
5. Tune validation filtering for Kitsu synonyms:
   - `synonyms` validator mismatched (strings vs integer values) and produces errors. Clean the ingest upstream to avoid per-row exceptions.
   - CSV/log is useful only for diagnostics; avoid per-row error IO on 75+ items if they can be ignored.

## 6. Safety note

No ingestion acceleration should be enabled from here unless rate limits and credential scopes are confirmed. The throughput figures and counts in this audit describe the historical run and are not authoritative about the current source catalogs.
