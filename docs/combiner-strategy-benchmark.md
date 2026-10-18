# Combiner strategy benchmark — all seven strategies

**Run:** 2026-10-16 04:20 → 10:49 PKT (6 h 28 m wall) · base `anilist` (15,015 entries) ·
every strategy written to its own `bench_<strategy>` scratch collection.
**Harness:** `scripts/benchmark-strategies-all.sh` · **Accuracy:** `php hiyori combine:consensus`
**Artifacts:** `storage/state/strategy-benchmark-20261016-042047.jsonl`,
`storage/state/bench-telemetry-*.json`, `storage/state/combine-consensus.json`,
`storage/logs/strategy-bench-20261016-042047*.log`.

This is the first time all seven registered strategies have been measured on the same
base, in the same code revision, with the same candidate machinery.

---

## Method

1. Snapshot `_hiyori_ids` + `_counters` (mongosh document copy — this host's `mongodump`
   has no `--nsInclude`), and restore them **before every run** so each strategy starts
   from an identical 15,880-row registry and the live registry is left exactly as found.
2. Drop `bench_<strategy>`, run `combine anilist --strategy <S> --into bench_<strategy>
   --export=none` under `/usr/bin/time -v` (2 h cap), capture exit code. Passthrough is
   deliberately **off**: these runs measure a strategy's ability to *merge*, and keeping
   unmatched entries would back every collection with one document per base entry and
   flatten the coverage column into a constant.
3. Snapshot the strategy's `combine-latest` telemetry.
4. Measure the scratch collection with one aggregation pass (coverage, linkage, duplicate
   identity keys, duplicate slug/id, new ids, uncovered registry ids).
5. Accuracy, separately: `ml:export-pairs --base anilist --limit 600 --negatives 4`
   → **7,494 pairs (873 organized-positive / 6,621 negative)**, replayed against every
   `bench_*` collection by `combine:consensus` and scored vs *consensus* (majority vote of
   the strategies), vs *organized* (the shipped labels) and vs *human* (review-UI labels).

### Read this before quoting numbers

* **The box was not idle.** Load average ~1.3 (a `node` dashboard, `htop`, another php).
  The relational strategies are round-trip bound (two Mongo calls per reference id per
  source), so **wall times are load-sensitive** — compare strategies against each other
  within this run, not against the older benchmark figures.
* **A single run ≠ live `organized`.** Each scratch collection is dropped first, so these
  are what one fresh `combine anilist` produces. Live `organized` (13,719) was built
  *cumulatively across many bases and runs*, so it carries more Kitsu linkage than any
  single run here. The `organized` row below is the baseline, not a target.
* **The accuracy sample is candidate-pool biased.** Negatives come from
  `MetadataFuzzyStrategy::candidates()`, so the sample measures *decision quality on the
  set of pairs `metadata_fuzzy` would even consider*. A strategy with a different (e.g.
  exact-relational) candidate pool is judged on pairs it never proposed; its recall is
  therefore understated. Coverage is measured separately, by document count.
* **`combine-latest` telemetry is merged, not replaced** (`Telemetry::update` is
  last-write-wins per field), so shared keys in `bench-telemetry-*.json` are inherited from
  whichever strategy wrote them first. Only `merges` and the scorer-specific keys are
  trustworthy per strategy.

---

## 1 · Cost and coverage

| strategy | docs | Δ vs `relational_fuzzy` | wall | entries/s | peak RSS |
|---|---:|---:|---:|---:|---:|
| `relational_mapping` | 6,805 | −41 % | 4 m 06 s | 61.2 | 75.0 MB |
| `relational_fuzzy` | **11,506** | — | 42 m 54 s | 5.83 | 74.7 MB |
| `fellegi_sunter` | 8,743 | −24 % | 52 m 19 s | 4.78 | 76.2 MB |
| `metadata_fuzzy` | 8,894 | −23 % | 70 m 17 s | 3.56 | 75.8 MB |
| `metadata_ml` | 8,984 | −22 % | 51 m 42 s | 4.84 | 76.2 MB |
| `semantic_rerank` | 8,978 | −22 % | 53 m 03 s | 4.72 | **500.2 MB** |
| `graph_merge` | 8,896 | −23 % | **91 m 51 s** | 2.72 | 75.6 MB |
| *`organized` (live, cumulative)* | *13,719* | — | — | — | — |

## 2 · Linkage quality

| strategy | has `mal` | has `k` | triple-linked | avg refs/doc |
|---|---:|---:|---:|---:|
| `relational_mapping` | 100.0 % | 75.1 % | 75.1 % | 3.34 |
| `relational_fuzzy` | 99.9 % | 52.1 % | 52.1 % | 3.31 |
| `metadata_fuzzy` | 99.9 % | 71.1 % | 71.0 % | 3.53 |
| `graph_merge` | 99.9 % | 71.4 % | 71.3 % | 3.54 |
| `fellegi_sunter` | 99.9 % | 71.4 % | 71.3 % | 3.54 |
| `semantic_rerank` | 99.9 % | 71.3 % | 71.2 % | 3.53 |
| `metadata_ml` | 99.9 % | 71.5 % | 71.4 % | 3.53 |
| *`organized` (live)* | *98.7 %* | *81.3 %* | *80.4 %* | *3.72* |

`relational_fuzzy` wins on raw volume but its extra 2,612 docs over `metadata_fuzzy` are
almost entirely **`anilist`-only rows with no Kitsu link** (52.1 % vs 71.1 % triple-linked).
Volume without linkage is not coverage in any useful sense.

## 3 · Identity safety (the release gate)

Duplicate `mal`/`al` values are the gate that must stay at 0 (`combine:dupes`).

| strategy | dup `mal` | dup `al` | dup `hiyori_id` | dup `slug` | new ids minted | registry ids left uncovered | gate |
|---|---:|---:|---:|---:|---:|---:|---|
| `metadata_fuzzy` | **0** | 0 | 0 | 0 | 4 | 4,829 | **PASS** |
| `graph_merge` | **0** | 0 | 0 | 0 | 5 | 4,828 | **PASS** |
| `fellegi_sunter` | **0** | 0 | 0 | 0 | 4 | 4,980 | **PASS** |
| `relational_mapping` | 2 | 0 | 0 | 0 | 386 | 7,300 | FAIL |
| `relational_fuzzy` | 6 | 0 | 0 | 0 | 684 | 2,897 | FAIL |
| `semantic_rerank` | 2 | 0 | 0 | 0 | 84 | 4,825 | FAIL |
| `metadata_ml` | 3 | 0 | 0 | 0 | 117 | 4,852 | FAIL |

Two things stand out:

* **Four of seven mints duplicate identity keys** and would need `combine:dedupe` before
  release. The duplicates are not the old title-artefact class — they are the strategies
  attaching the same source `mal` to two different docs.
* **`metadata_fuzzy`, `graph_merge` and `fellegi_sunter` allocate ~4–5 new ids** while
  `relational_fuzzy` allocates **684** and `relational_mapping` 386. Under "Hiyori IDs are
  allocated once and never re-issued", the relational strategies churn the registry ~100×
  more. Uncovered-registry-id counts are just the inverse of coverage (low coverage ⇒ many
  ids with no doc), not corruption.

### Determinism

`metadata_fuzzy` re-run into a fresh scratch collection reproduced its output **identically**:

```
bench_metadata_fuzzy        docs=8894  sha256=88a49d8b4035d369…
bench_metadata_fuzzy_rerun  docs=8894  sha256=88a49d8b4035d369…
```

Fingerprint = SHA-256 over every document's `hiyori_id` + `title` + `reference_ids`, sorted by
`hiyori_id`. The strategies iterate `find()` with no explicit sort and use no sampling, so
document order is stable; this single re-run is consistent with the combiner being
deterministic. **Only `metadata_fuzzy` (the production default) was re-run** — the other six
were not.

## 4 · Accuracy (7,494 pairs — 873 `organized`-positive, 6,621 negative)

| # | strategy | predicted | agreement | P | R | F1 vs consensus | **F1 vs `organized`** |
|---|---|---:|---:|---:|---:|---:|---:|
| 1 | `metadata_fuzzy` | 607 | 1.000 | 0.998 | 0.998 | 0.998 | **0.812** |
| 1 | `graph_merge` | 607 | 1.000 | 0.998 | 0.998 | 0.998 | **0.812** |
| 3 | `metadata_ml` | 606 | 0.999 | 0.997 | 0.995 | 0.996 | 0.810 |
| 4 | `semantic_rerank` | 601 | 0.999 | 1.000 | 0.990 | 0.995 | 0.806 |
| 5 | `fellegi_sunter` | 599 | 0.999 | 0.998 | 0.985 | 0.992 | 0.804 |
| 6 | `relational_fuzzy` | 575 | 0.981 | 0.906 | 0.858 | 0.882 | 0.787 |
| 7 | `relational_mapping` | 267 | 0.950 | 0.936 | 0.412 | 0.572 | 0.468 |

Consensus positives = 607 of 873 `organized` positives: **266 pairs the shipped pipeline
joined are not reproduced by a majority of strategies on a fresh single run.** That is the
cumulative-vs-single-run gap showing up in the labels, not a bug.

Human F1 is **n/a** — the `_labels` store is still empty. The review UI
(`QualityPage` → "Looks right"/"Wrong" → `POST /api/labels`) and
`php hiyori labels add` both write pair labels that feed the `human F1` column with no code
change; they just need a reviewer to spend the time.

---

## Ranking

Two axes matter and they disagree, so this is a Pareto front plus a weighted composite,
not a single truth. Weights are explicit so they can be argued with:

```
score = w_acc · F1(organized) + w_cov · (docs / max_docs)     [default w_acc = w_cov = 0.5]
```

| rank | strategy | F1(org) | coverage factor | **composite** | gate | verdict |
|---:|---|---:|---:|---:|---|---|
| 1 | `relational_fuzzy` | 0.787 | 1.000 | **0.894** | FAIL | Highest reach, but 6 dup keys and worst linkage |
| 2 | `metadata_ml` | 0.810 | 0.781 | **0.796** | FAIL | Best accuracy-per-doc of the scorers |
| 3 | `semantic_rerank` | 0.806 | 0.780 | **0.793** | FAIL | 6.6× peak memory for no gain |
| 4 | `metadata_fuzzy` | 0.812 | 0.773 | **0.793** | **PASS** | Shipping default; best accuracy, passes gate |
| 5 | `graph_merge` | 0.812 | 0.773 | **0.793** | **PASS** | Ties fuzzy on accuracy, +31 % wall for it |
| 6 | `fellegi_sunter` | 0.804 | 0.760 | **0.782** | **PASS** | Fastest of the accurate cluster |
| 7 | `relational_mapping` | 0.468 | 0.591 | **0.530** | FAIL | Recall 0.41 — not viable alone |

### Reading of the ranking

* **Keep `metadata_fuzzy` as the default.** It is the most accurate gate-passing strategy
  *and* the cheapest of the accurate cluster apart from `fellegi_sunter`, which trails it
  by 0.008 F1. Nothing in this run justifies switching.
* **`graph_merge` is not worth a switch** — identical accuracy to `metadata_fuzzy`, 31 %
  slower, and it needs the most memory of the non-embedding strategies.
* **`metadata_ml` is the one to revisit.** It matches the fuzzy scorer within 0.002 F1
  while being ~27 % faster, and it is the only strategy whose scores are calibrated
  (min 0.5055, max 1.0). Its 3 duplicate keys are the blocker to promoting it — that is a
  `combine:dedupe`-shaped problem, not a modelling one.
* **The relational strategies lose on both axes.** `relational_mapping` has recall 0.412;
  `relational_fuzzy` buys +29 % docs that are ~20 points weaker in triple-linkage *and*
  is the slowest-per-doc. Neither should carry a release.
* **`semantic_rerank` costs 500 MB** — 6.6× every other strategy — for F1 between
  `fellegi_sunter` and `metadata_ml`. Only worth it if a hosted embedding backend is
  added; the local n-gram fallback is not paying for itself.

### Limitations

* Single seed run per strategy; no repeats, so no confidence intervals on wall time.
* Timing is load-sensitive (see caveats) — treat the ordering, not the ratios, as reliable.
* Accuracy is measured on `metadata_fuzzy`'s candidate pool (see caveats).
* No human labels yet; the `human F1` column is unpopulated.
* Manga (`anilist_manga`, 38,097) was **not** benchmarked in this run.
* Determinism verified for `metadata_fuzzy` only (a re-run hash-matched exactly); the other six strategies were not re-run.

---

## Reproduce

```bash
# cost + coverage + identity, all 7 strategies, isolated
scripts/benchmark-strategies-all.sh anilist

# accuracy on one common pair sample
php hiyori ml:export-pairs --base anilist --limit 600 --negatives 4 \
    --out storage/ml/pairs-consensus.jsonl
php hiyori combine:consensus --pairs storage/ml/pairs-consensus.jsonl
```

The harness restores `_hiyori_ids`/`_counters` after every run and never writes to
`organized`, `organized_manga`, or any ingested source table.
