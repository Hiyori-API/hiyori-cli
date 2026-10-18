# Architecture audit — 2026-10-19

Scope: the CLI, the HTTP front controller, the pipeline stages and the
infrastructure around them. Method: static reading plus read-only queries
against the live database and `storage/state/`. Nothing was executed against
production data; the fixes below are covered by the gates listed at the end.

---

## 1. The shape

```
hiyori ──► src/Bootstrap.php ──► container ──► 29 Commands ──► 17 Services
             (hand-wired, 130 lines)                                 │
public/index.php ──► 55 functions, 47 route guards ──────────────────┤
 (2,420 lines)        a second implementation of the same domain     │
                                                                     ▼
  web/ (React SPA) ◄── served by index.php :8080            MongoDB + storage/state/*.json
                                                                     ▲
  scripts/*.sh ──► ingest → combine → hiyori-id → dupes → dedupe → export
```

Three facts explain most of the findings:

1. **There are two composition roots.** `src/Bootstrap.php` builds the CLI's
   object graph; `public/index.php` builds its own with different arguments
   (different Mongo timeouts, a second `RateLimiter`, its own copies of export
   config, explorer queries, env handling and analytics). Anything that must be
   consistent across the two has to be edited twice, and is currently
   inconsistent.
2. **There is no stage contract.** Stages are `void` static functions dispatched
   by string. Nothing declares what `ingest` must produce for `combine` to be
   correct, nothing counts what was discarded, and the orchestrating order
   exists only in shell scripts and CI.
3. **State has no owner.** 36 JSON files under `storage/state/` are written by
   HTTP, read by the pipeline, and vice versa, with no shared abstraction, no
   schema and inconsistent atomicity.

---

## 2. Fixed in this pass

### Security

- **Six mutating routes had no origin check.** `/api/export/run`,
  `/api/rejections/promote`, `/api/explore/update`, `/api/explore/bulk`,
  `/api/explore/undo` (POST) and `/api/explore/import`. With no
  `DASHBOARD_TOKEN` — the default — any page the operator visited could reach
  them. `bulk` and `import` also accepted any HTTP method.
- **The check now runs once, before dispatch**, for every method other than
  GET/HEAD/OPTIONS, so a newly added route cannot be born unguarded. The three
  mutating GETs (`/api/backup?run=1`, `/api/action`, `/api/combine`) keep their
  explicit calls because no method check can identify them.
- **The origin check did not stop DNS rebinding.** It allowed any request whose
  `Origin` host equalled the `Host` header — exactly what rebinding makes true
  (the attacker serves from `evil.com` after pointing that name at `127.0.0.1`).
  `Host` must now name a literal address or `localhost`. Access by DNS name
  requires `DASHBOARD_TOKEN`.
- `sameOriginRequest()` moved to `public/http-guard.php` and is covered by
  `tests/Unit/HttpGuardTest.php` (rebinding, IPv6 literal, LAN address, origin/
  host mismatch). It previously had no test because it lived in a file that
  cannot be included without dispatching a request.

### Manga identity (the data-corruption root cause)

- The identifier table only knew `/anime/` URL shapes. The manga sources are
  configured with `al_manga` / `k_manga` / `mal_manga`, so **no manga document
  resolved any id**: `reference_ids` was `[]` for 57,962 of 65,373
  `anilist_manga` rows. That made the ingest `exists()` check always false —
  re-running a manga source re-inserted the whole catalogue instead of resuming
  — and left `combine` able to see only the 2,161 rows that happened to match an
  anime id. Added `AniListManga`, `KitsuManga`, `MyAnimeListManga`.
- The manga models fed **related works** into the identity map, and `getModel()`
  is last-wins, so a related manga overwrote the entry's own id and a related
  anime claimed `al` on a manga document. That is the collision behind the 2,094
  `organized_manga` rows carrying another work's id (`MONSTER` manga claiming
  anime id `al=19`). Identity is now the entry's own URL plus cross-source links;
  related works remain as links.
- `pairsToLinks()` matched on a host substring, so `myanimelist.net` produced
  both an anime and a manga URL for every external link. It is now kind-aware.
- Manga MyAnimeList parsed external links with `explode('/', $url)[0]`, which is
  `https:` — a substring of every `ENTRY_URL` — so one link would have produced
  an id for every known site. Now parsed with `parse_url()`.

### Export and release gate

- **`export` invented ids.** A document without `hiyori_id` was published as
  `hiyori_id: 0`, filenames fell back to the row position, and the lookup was
  keyed by that position — with no error. `emit()` now refuses and names the
  remedy (`hiyori-id --backfill`).
- **The release gate checked the wrong namespace.** `combine:dupes` applied
  `mal,al` to every collection, including `organized_manga`; and a collection
  with zero documents passed vacuously. Keys are now derived per collection and
  an empty collection fails (with `--allow-empty` to override).

### Observability

- **`/metrics` was a decoy.** `MetricsCollector` builds an `InMemory` registry
  per request and nothing ever incremented it, so the endpoint emitted only
  `php_info` while Prometheus scraped it happily. It now reports real gauges:
  `organized`, `organized_manga` and registry document counts (via
  `estimatedDocumentCount`), plus `ingest_processed` per source from state.
- `prometheus.yml` scraped `hiyori:9090`, a container that never served HTTP;
  it now targets the dashboard.
- **The breaker panel was a permanent false green.** `RateLimiter` held its
  breakers in instance arrays, so the HTTP process — one fresh limiter per
  request — had never observed anything, and `getStats()` answered
  `closed` / `100 tokens` for every key it had not seen. The UI therefore always
  rendered healthy rows and the `circuit_open` / `low_tokens` alerts could never
  fire. Breaker state is now persisted to `storage/state/rate-limit-state.json`
  on each transition and hydrated on construction, `hasObserved()` distinguishes
  real state from a default, and `HealthCheck` omits limiters that have not run
  (the dashboard already shows "No limiter state reported yet"). The health check
  also covers the three manga sources, which it never listed.
- **The limiter's logger discarded everything.** `new Monolog\Logger('ratelimiter')`
  with no handler is a black hole, so every breaker transition and retry warning
  was dropped. It now writes to `storage/logs/ratelimiter-*.log`.

### Service layer

- `ingest:all` exited 0 when every source failed.
- `APP_DEBUG=false` is the string `"false"`, which is truthy — it enabled debug.
- `SourceConfigurationFactory` read an uninitialized typed property; its
  friendly exception was unreachable.
- `StrategyConfigurationFactory` kept the previous strategy on an unknown name,
  and `get()` returned `null` against a non-nullable return type.
- The health probe's Mongo client had no timeout (30 s driver default), so
  `hiyori health --watch` could appear to hang.
- `Hiyori\Exception\InvalidArgumentException` did not extend the SPL type it is
  named after, so no catch for either would have worked.

### Tooling and infrastructure

- `composer lint` overrode `phpstan.neon`'s paths (`analyse src`), silently
  skipping `public/`; CI worked around it with its own invocation. One command
  now, used by both. A `php` floor (`^8.3`) is declared, matching CI and the
  Dockerfile against a local 8.4 runtime.
- `docker-compose.yml` ran `php hiyori list` under `restart: unless-stopped`,
  which restarts on any exit including success, so it looped forever; and the
  stack had no dashboard service. The CLI is now a one-shot `cli` profile, and
  there is a dashboard service on `:8080` with a healthcheck.
- `.env.example` documented a `MAL_CLIENT_SECRET` that nothing reads and omitted
  the `LLM_*`, `SCHEDULE_*` and telemetry variables that are read; `APP_ENV` is
  advertised in three places but no code branches on it.

### Second pass — making success provable

- **`combine` coverage is now a number, not an assumption.** Every strategy
  returns a `CombineStats` (examined / merged / passthrough / unmatched /
  unresolved base id), `combine` prints it, and `--min-coverage` fails the run
  below a floor (default 40 %; a run that examines nothing always fails).
  `Hiyori::combine()` returns the stats instead of discarding them.
- **Identity allocation converges on a retry.** Backfill looks for a row that
  already owns the identity before upserting keyed on the reference set, so a
  crash between registry insert and document update reuses the same id rather
  than burning a second block.
- **Registry identity indexes are partially unique** on `(kind, refs.<key>)`,
  created per key, refusing where conflicts exist. `verify` now detects duplicate
  references in both the registry and the documents, and
  `reconcileRegistryOrphans()` reports rows that own an id with no document.
- **Telemetry read-merge-write is locked** (sidecar `flock`; the data file is not
  locked because `rename` replaces its inode). The parallel-ingest request
  counter is no longer lost, and write failures are logged instead of swallowed.
- **The manga identity repair was applied** — 195,986 documents, with the old
  values kept in `_repair_reference_ids_backup`. See
  `docs/manga-identity-repair.md`.

---

## 3. Open findings, by priority

### P1 — still open

- **`refs.mal` cannot be uniquely indexed yet.** 137 `(anime, mal)` groups still
  have two or three holders (e.g. `mal=5` owned by ids 2, 11882 and 13641).
  `ensureRegistryIndexes()` creates the other identity indexes and reports `mal`
  as blocked rather than failing; `hiyori-id --resolve-duplicate-refs` resolves
  it explicitly, ranking holders by document ownership, then base corroboration,
  then smallest id, and never moves an id. Run it once the manga rebuild has
  finished (it writes to the same registry the rebuild is allocating from).
- **No stage orchestration.** CI, `finalize-merges.sh` and the dashboard each
  implement a different subset of the chain; `combine`'s lock is the only guard,
  and `combine:dupes`, `combine:dedupe`, `hiyori-id`, `export` and `release`
  have none. Locks return `SUCCESS` on contention, so a driver cannot tell
  "busy" from "done".
- **`combine` is not resumable.** It iterates a plain cursor and writes telemetry
  only at the end, so a crash leaves the previous run looking current. The manga
  cursor timeout in `finalize-merges.log` is how `organized_manga` ended at 2,161.
- **`combine` never deletes.** Stale documents are published forever (upsert-only),
  so a rebuild only converges if the target is cleared first — as this one was.
- **`combine` examines the other kind's sources.** A manga base is compared
  against the anime collections too; the `type` anchor veto rejects those
  matches, so the work is pure cost. Skipping them would be a large speed-up but
  changes match semantics when `anchor_rejection` is disabled, so it was left
  alone.

### P2 — the HTTP layer is a second implementation

Of 55 functions in `public/index.php`: ~10 are genuinely HTTP-only, ~20
duplicate an existing service (rate limits ×3, export state, telemetry tail,
`ui.json`, explorer facets), and ~25 are domain logic with no service owner
(`coverage` 67 lines, `explore` 55, `alerts` 50, env handling ×8). Route order is
a linear `if` chain with handler definitions interleaved, which is why the file
cannot be included by a test: it has **zero** route coverage today.

Suggested sequence (each step ends with the handler calling a service and the
function deleted):

1. Route table + central guard (the guard already exists; the table prevents
   the next unguarded route).
2. `Service\SettingsStore` + `RateLimiter::effectiveLimits()` — this also fixes
   the three divergent rate-limit precedence rules.
3. `Service\EnvFile` (102 lines, self-contained).
4. `ExportConfig::save()` + collapse the 15 `export*` functions onto the service.
5. `Service\Backup`, `ExplorerQuery`, `Coverage`, `Stats`, `AlertEngine`,
   `CombineRun`, `ProcessRunner`.
6. `runShellCommand()` should take an argv array rather than a command string;
   nothing is injectable today, but the signature advertises safety it does not
   provide.

### P3 — repo and config hygiene

- **`.git` is 91 MB for a 354-file repo.** 41.7 MB is a committed `gh` binary,
  12.7 MB its tarball, the rest repeated multi-MB log files. Only fixable by
  history rewrite; deliberately not done here.
- **Configuration has no schema.** `SourceConfiguration` does unguarded
  `$config['name']` on an `include`d PHP file; `StrategyConfigurationFactory`
  accepts arbitrary JSON and ignores unknown keys; `ExportConfig` swallows
  malformed JSON. Every misconfiguration fails silently and far from its cause.
- **`ConsoleFactory`** builds a second `ArgvInput`/`ConsoleOutput` detached from
  the running Application, so `-q`/`--ansi` and test output substitution do not
  apply to ingestion progress.
- **`APP_DEBUG` is the only mode switch**; the `APP_ENV` values set in
  `docker-compose.yml` and `phpunit.xml.dist` have no effect.

---

## 4. Not verified

- No stage was executed and no failure was injected; crash claims come from code
  paths and logs.
- The live database was not modified. Counts quoted are from a read-only
  snapshot and `myanimelist_manga` was ingesting during the audit.
- `sem_acquire`/`SEM_UNDO` lock release was reasoned about, not observed.
- The dashboard's breaker panel was traced through `HealthCheck` and
  `SettingsPage` but not seen rendering against a live server.
- Why PHPStan (level 5) reports no error on `StrategyConfigurationFactory::get()`
  despite the nullable-return violation.
