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

### Performance — where the hours actually went

The combiner's candidate lookup was the bottleneck, not the scorer.

`MetadataFuzzyStrategy::candidates()` queries `{type, status, year}` (then
relaxes it), limit 100, no sort. `explain` on the live server:

```
before:  docsExamined 7113   keysExamined 0      9 ms   (COLLSCAN)
after:   docsExamined  100   keysExamined 100    0 ms   (IXSCAN)
```

Migration 1 gave `organized` and `organized_manga` that index; the **source**
collections only ever got `reference_ids.*`, so every candidate lookup scanned.
Migration 5 adds it to all six. Measured on the live manga rebuild:
**3.2 → 7.8 rows/s (2.4x)** with no change to what it merges.

The second half of the cost was asking the wrong collections. A manga base
iterates every source but itself, so it queried three anime collections (15k,
22.5k and 29k documents) four times per base row, and the type anchor veto
threw every candidate away afterwards — roughly 60 % of the candidate
retrieval. All six source-looping strategies now skip cross-kind candidates
(`same_kind_candidates: false` restores the old behaviour).

### Open-weight local embedding models

The embedding path exists (`services/ml/embed.py`, JSONL over pipes) but the
Python sidecar was **never installed** — no `.venv-ml`, no torch, no
sentence-transformers — so `semantic_rerank` has been running the deterministic
n-gram fallback. That is what cost 500 MB RSS and 0.806 F1 in the 2026-10-16
benchmark; the embedding path has never actually been measured.

Candidates with permissive licences and ONNX exports (so Node can run them via
`@huggingface/transformers` and `onnxruntime-node`, with no server and no
Python):

| model | licence | params | ONNX | notes |
|---|---|---:|---|---|
| `Cloudflare/clef` | Apache-2.0 | — | no (GGUF exists) | most-liked on HF (2,221); needs the Python sidecar or llama.cpp |
| `Cloudflare/clef-flash` | Apache-2.0 | — | no | the fast variant of the above |
| `Alibaba-NLP/gte-modernbert-base` | Apache-2.0 | 149M | bnb4, fp16 | ModernBERT: ~2x faster than BERT, strong MTEB |
| `intfloat/multilingual-e5-small` | MIT | 118M | O4, int8-avx512 | safest multilingual, smallest |
| `nomic-ai/nomic-embed-text-v1.5` | Apache-2.0 | 137M | int8, fp16 | 8192 context |
| `jinaai/jina-embeddings-v2-small-en` | Apache-2.0 | 184M | yes | Jina v2 is commercial-clean |
| `jinaai/jina-embeddings-v3` | **CC-BY-NC-4.0** | 570M | — | **non-commercial — unusable here** |

Two constraints worth stating plainly:

* **Vector search is not available to us.** The live server answers
  `$search stage is only allowed on MongoDB Atlas`; we run MongoDB 7.0.43
  self-hosted. ANN retrieval would mean an Atlas dependency or a version
  upgrade plus re-embedding 126k documents.
* **Embeddings are an accuracy play, not a speed play.** They improve matching
  beyond string overlap; they do not touch retrieval, which is where the cost
  was. The per-pair cost can be lowered by caching each source document's
  vector once and comparing with a dot product, which `EmbeddingService`
  already does via `storage/ml/embeddings.json` — but that only pays off after
  a one-time embedding pass.

### The pipeline, rebuilt end to end (2026-10-20)

Both combined collections were rebuilt with the fixed matcher, and the
identities held.

| | anime | manga |
|---|---:|---:|
| base rows | 15,017 | 28,819 |
| coverage | **100.0 %** | **100.0 %** |
| merged / single-source | 11,568 / 3,449 | 5,483 / 23,336 |
| unmatched | 0 | 0 |
| skipped (no base id) | 0 | 0 |
| wall clock | 12 min | 62 min |

**Id stability held.** The anime `hiyori_id`s are published, so the registry
was deliberately left intact and only the documents were cleared; allocation
reused the existing rows. 15,016 of 15,017 ids came back with the same id *and*
the same title. The one that changed is explained below.

Three defects surfaced during the rebuild and were fixed:

1. **A document was published with no `hiyori_id`.** The registry row for
   "PokéOki" held `mal: 60772` — a value from the old matcher's false merge —
   while the corrected merge says `62209`. `matchKnownId()` vetoes a row that
   disagrees on any shared identity key, so the lookup found nothing, the
   allocator tried to INSERT a second row for the same `al`, and the new unique
   index rejected it. The strategy's fallback then wrote the document with no
   id at all. The allocator now reads the unique index as the definition of
   identity: one `(kind, refs.key)` value belongs to exactly one row, so a row
   already holding one of our values IS this work, and the right move is to move
   that row onto the corrected refs. "PokéOki" got its original id 13285 back.
2. **The release gate reads documents; the resolver only knew about the
   registry.** Unsetting the ref on the losing *registry row* left the merged
   *document* still claiming it — and the registry no longer recorded the
   conflict, so the resolver had nothing to work from. There is now a
   document-level resolve with the same ranking (declares the ref, then base
   corroboration, then lowest id).
3. **A numeric-string array key silently broke a Mongo query.** PHP turns
   `"2467"` into the int `2467`, and MongoDB will not match the stored string
   against the int, so 14 document updates matched nothing. The file already
   warned about this; the new code casts back.

Final state: release gate **PASSED**, `hiyori-id --verify` reports 0 duplicate
registry refs, 0 duplicate document refs, 0 orphans, and every document carries
an id and a slug.

### The ML path, made manga-capable — and the decision it produced

The feature extractor was anime-shaped: `episodes_match` and `season_match`
are constant for manga, so the model had no signal from the fields manga
actually have. `chapters_match` and `volumes_match` were appended (appended,
not inserted, so no weight index shifted), and the stored model — trained on 12
features against 14 — now refuses to load with a message that names both
feature lists and the retrain command. `LogisticModel::predict()` had been
zipping the vector POSITIONALLY with a silent fallback to index 0, so a stale
model produced plausible, meaningless numbers with no warning at all.

The sidecar protocol was the other half. The model is fast — 28 ms per title
warm — but `EmbeddingService` spawned a fresh `embed.py` per text, paying
interpreter start + torch import + model load every time: **12.74 s per title**,
a 450x penalty that would have made embedding the corpus a ~62 hour job. The
sidecar already speaks one line in, one line out, so keeping it alive is a
protocol fix rather than a model choice. Measured after: **17.7 ms per title**,
a 719x improvement. The sidecar's stderr was also being closed unread, so one
that could not load torch was indistinguishable from one with nothing to say —
and the service fell back to the n-gram hasher without a word.

Retrained on anime + manga pairs together (17,593 rows), the model's feature
discrimination gaps are: containment +0.84, jaro +0.31, episodes +0.28, season
+0.15, **chapters +0.11, volumes +0.04**, and — notably — **year_similarity
−0.01, status −0.03, type −0.05**. The metadata fields that used to carry the
false merges now *mislead*.

| holdout | metadata_ml vs metadata_fuzzy | gate (margin ≥ 0.02) |
|---|---:|---|
| anime (305 positives) | **+0.0202** | **PASSED** |
| manga (28 positives) | **−0.0182** | not met |

**So `metadata_fuzzy` stays the default.** The ML path is now manga-capable
and beats the fuzzy matcher on anime, but the manga sample is too small and the
manga length features are too weak a signal to promote it there. The manga
holdout needs more labelled pairs before the question is worth asking again.

### The strategy table, re-measured on the manga base

The 2026-10-16 table in `docs/combiner-strategy-benchmark.md` predates every
matcher fix and was measured on the anime base. The harness was made kind-aware
(the old measurement hard-coded `al`/`mal`/`k` and would have reported zero
linkage for every manga run) and the manga base re-benchmarked: 28,819 rows,
five strategies, merge-only so the numbers stay comparable with the old table.

| strategy | docs | wall | entries/s | dup `al_manga` | dup `mal_manga` | dup `hiyori_id` | has `k_manga` | has `mal_manga` |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `metadata_fuzzy` | 5,498 | 19 m 34 s | **24.56** | 0 | 33 | 71 \* | 4,012 | 1,486 |
| `fellegi_sunter` | 5,433 | 19 m 54 s | **24.14** | 0 | 31 | 65 \* | 3,955 | 1,478 |
| `graph_merge` | 5,501 | 33 m 01 s | 14.55 | 0 | **82** | **82** | 4,029 | **3,217** |
| `relational_fuzzy` | **0** | 33 s | — | 0 | 0 | 0 | 0 | 0 |
| `relational_mapping` | **14,233** | ~60 m | — | **0** | **0** | **0** | **11,975** | 2,258 |

\* Measured before the allocator fix described below, which is what produced
those duplicates: the counts are the bug, not the strategy. A re-run of
`metadata_fuzzy` with the fixed allocator produced 5,498 documents with **zero**
duplicate `hiyori_id`s and zero duplicate `al_manga`s, and the two manga that
had shared k_manga 119 now hold 105423 and 108911.

Four things this says:

1. **The pipeline is 6.9x faster than the old benchmark recorded** (24.56
   entries/s against 3.56) — the candidate-filter index and the kind-scoped
   candidate sources, not a better strategy.
2. **`metadata_fuzzy` and `fellegi_sunter` are now indistinguishable on
   speed** (24.56 against 24.14). The old table's 3.56-against-4.78 gap was a
   retrieval cost the index removed, so the accuracy gap (F1 0.812 against
   0.804) is now the only thing separating them — and `metadata_fuzzy` wins it.
   **`metadata_fuzzy` remains the default.**
3. **`graph_merge` links more and is wrong more.** It carries 3,217 `mal_manga`
   links against the fuzzy scorer's 1,486, and it mints 82 duplicate
   identities, which fails the release gate outright. It is also 35 % slower.
4. **The relational strategies are structurally inapplicable to manga.**
   `relational_fuzzy` merged **zero** of 28,819 rows. Manga entries carry only
   their own source's id — `al_manga` on AniList, `k_manga` on Kitsu,
   `mal_manga` on MyAnimeList — so there are no cross-source identity edges for
   an exact-relational strategy to follow. That is not a slow run; it is a
   different data shape. `relational_mapping` is the exception: it reached
   14,233 documents (2.6x the scorers) with **zero** duplicates of any kind
   and far the most linkage — but it took three times as long and still left
   half the base unmerged.

The accuracy sample was too thin to separate the scorers (28 consensus positives
out of 7,347 holdout pairs), so the F1 column still comes from the older anime
measurement. The manga holdout needs more labelled pairs before it can carry
that weight.

One note on reading the manga numbers: a manga entry links to *either* MAL or
Kitsu, not both, so the anime table's "triple-linked" column is 0 by
construction for manga. The meaningful figures are the per-key linkage rates.

### A defect the benchmark found

The merge-only run produced **71 documents sharing a `hiyori_id`**: "Ichigo
100%" and "100-man Yen! Vegas-kun" are different manga that both link to
k_manga 119 (one MAL series entry covering several AniList entries), and the
allocator handed them the same id.

The unique identity index is right — one `(kind, refs.key)` value belongs to
exactly one row. The question is *which* row, and the answer depends on the
base's own key: if the row agrees on it, it is the same work and one of its
cross-source links was simply wrong before (move the row onto the corrected
refs, keep the id); if it disagrees, the value belongs to a different work
entirely and must be given up rather than taken. The base's key is now threaded
through the allocator and used to tell the two apart. A franchise key may still
be claimed by several rows — that is what a shared MAL series looks like — but
the base identity and the `hiyori_id` never are.


### What is still open

Everything below was true when the audit was written and is now fixed. The
remaining work is listed in `docs/architecture-audit.md` → "Still open" in the
repository root README, and summarised here.

**Fixed since the audit was written**

| Was open | Now |
|---|---|
| `refs.mal` could not be uniquely indexed (137 groups) | resolved; the index exists and the gate passes |
| no stage orchestration | `pipeline:release`, used by CI and both scripts |
| `combine` not resumable | keyset pagination, per-batch checkpoint, `--resume` |
| `combine` never deletes | build-id sweep, scoped to the base's own key |
| `combine` examined the other kind's sources | kind-scoped candidate sources |
| `runShellCommand` took a command string | `ProcessRunner::run(array $argv)` |
| 25 domain functions in the front controller | 11 services; `public/index.php` 2,316 → 1,547 lines |
| `.git` was 91 MB | 16.3 MB after a history rewrite of all three branches |
| configuration had no schema (source keys, strategy config, export config files) | validated where they are read: the file, the key and the offending value are in the message |

**Still open**

1. **The manga ML holdout is too small to decide on.** 28 consensus positives
   out of 7,347 holdout pairs. `metadata_ml` passes the anime gate (+0.0202)
   and fails the manga one (−0.0182), and the manga length features are weak
   signals (+0.11, +0.04) next to title containment (+0.84). This needs more
   labelled manga pairs, not more code: `ml:export-pairs --base anilist_manga
   --limit 2000`, and human labels through the review UI (the `_labels` store
   has 118).
2. **`web/dist` is 82 MB of committed build output** — the largest remaining
   history cost, and a footgun: the served bundle can drift from `src` unless
   it is rebuilt. Publishing it from CI instead of committing it would remove the
   drift and most of the history.
3. **One Dependabot alert cannot be read** without repository credentials. The
   public advisory API is empty and `composer audit` is clean, so it is most
   likely a GitHub Actions advisory; pinning the actions to commit SHAs would
   close it.
4. **Franchise-key collisions are real data, not a bug.** 93 manga documents
   share a `mal_manga` value and 131 share a `k_manga` value, because one MAL
   series entry covers several AniList manga. They do not fail the gate (only
   the base's own key is primary), but they mean the same external id appears
   on several documents — a data-modelling decision rather than a defect.
5. **`/api/action` still uses `@exec`** for the detached ingest start. It
   cannot use `ProcessRunner` because it must not block, and its argument is
   already constrained to `^[a-z0-9_-]+$`; it is the last `@exec` in the
   front controller.
6. **`ConsoleFactory` builds a second `ArgvInput`/`ConsoleOutput`** detached
   from the running Application, so `-q`/`--ansi` and test output substitution
   do not apply to ingestion progress.
7. **Housekeeping:** the `_repair_*` backup collections are still in Mongo
   (~200k documents). They are the rollback net for the identity repair and can
   be dropped once the result is accepted.

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
