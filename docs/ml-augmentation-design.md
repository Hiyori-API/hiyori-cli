# Hiyori CLI — ML Augmentation Design (v2: Embeddings + LLM)

**Status:** Level-0 (local, free) implemented: `EmbeddingService` + `SemanticRerankStrategy` + `services/ml/embed.py`. **Level-0 embeddings hardened:** `ML_MODEL`/`ML_EMBED_VERSION` config, a model/version-namespaced vector cache, pinned `services/ml/requirements.txt` and a documented smoke test. **Level-0.5 implemented:** `ml:export-pairs` (labelled JSONL ground truth), `ml:train` (pure-PHP logistic regression), `FeatureExtractor`/`LogisticModel`, and the `metadata_ml` combine strategy (anchor rejection + ML second stage, `ml_*` fields on merges). Levels 1–2 (LLM judge, calibrated/XGBoost classifier, migration v4) remain design only. Tracked in `docs/dashboard-roadmap.md` → **Batch 4 — ML / Augmentation**.
**Scope:** Improve the combine decision (merge vs. keep separate) by augmenting `metadata_fuzzy` with lexical features, multilingual embeddings, a cross-source tag taxonomy, a cheap LLM tie-breaker for ambiguous pairs, and a trained (Clef/JEB-style) decision model.
**Baseline (already shipped):** `metadata_fuzzy` is the default combine strategy. Tuned weights `title 26.4 / type 5.7 / episodes 26.2 / status 0.6 / season 16.4 / year 23.2 / tags 1.5 / synonyms 0`. Threshold `70`. Result on anime: `organized = 10,770`, triple-linked `8,358`, confidence histogram `70–80 = 3,705 · 80–90 = 680 · 90–100 = 6,385`. `confidence` is stored per merged document; `/api/histogram` exposes it in 10 bins; `/api/sample` is the human-in-the-loop agree/disagree sampler.

---

## 1. Why (problem statement)

`metadata_fuzzy` is a hand-tuned weighted sum of string/enum similarities. Three weaknesses motivate ML:

1. **Titles are multilingual and multi-script.** Anime/manga titles appear in Japanese, Korean, romaji, and English. Current title similarity is `normalizeTitle` + token Jaccard + containment; synonym overlap is a hard 0.9/0.0. `synonyms` weight is currently `0` because it is informational only. Cross-language synonyms (“Attack on Titan” vs “Shingeki no Kyojin” vs 進撃の巨人) are missed or scored weakly.
2. **Tags are effectively unused.** `DEFAULT_WEIGHTS['tags'] = 1.5` and docs say “Tags are intentionally near-zero: different sources use disjoint tag sets.” There is no cross-source tag taxonomy, so genres/themes cannot help matching.
3. **The `70–85` band is genuinely ambiguous.** 3,705 accepted merges (34% of all) land in `70–80` and a further chunk in `80–90`. Fixed weights cannot express pairwise interactions (e.g., “same year + same type but title only partially overlaps”).

Goal: **raise recall on multilingual titles without increasing false merges**, and make the decision **learned + calibrated** rather than only hand-tuned.

Non-goals (this design): replacing ingestion, changing source schemas, or running a heavyweight GPU service. Everything here must run on the existing single-process PHP + MongoDB stack with a free/cheap budget.

---

## 2. Current combine surface (integration points)

| Item | Location | Notes |
|---|---|---|
| Strategy registry | `src/Service/StrategyConfigurationFactory.php` `AVAILABLE_STRATEGIES` | ✅ `MetadataMlStrategy::class` registered. |
| Strategy impl | `src/Service/Combiner/Strategy/MetadataFuzzyStrategy.php` | Reuse `findBestMatch`, `candidateFilterChain`, `calculateScore`, `fieldSimilarity`, `jaroWinkler`, `titleSimilarity`. |
| CLI entry | `src/Commands/CombinerCommand.php` (`combine`, `-s`, `-t`, `-w`, `--into`, `--config`) | ✅ shipped as `--strategy metadata_ml --config '{...}'`; `--llm-judge` still pending. |
| Persistence | `src/Service/Database.php::mergeIntoOrganized(..., array $extra)` | `$extra` lands on the merged doc; already carries `confidence`. |
| IDs | `Migrator` v3 (hiyori_id registry) / v4 (`ml_fields_and_indexes`) | ✅ v4 adds `_labels`/`pair_rejections`/`embeddings`/`llm_judgements` + `confidence`/`ml_score`/`ml_decision` indexes. |
| HITL sampler | `/api/sample?collection=&min=` + `POST /api/labels` in `public/index.php` | ✅ Quality page agree/disagree stamps `_labels`; `labels` CLI mirrors it. |
| Histogram | `/api/histogram?collection=` (10 bins, width 10, score+confidence) | Add `ml_score` binning. |
| Cost controls | `.env` (`OPENAI_API_KEY`, etc.) via Settings → Environment | Reuse masked env manager. |

Key storage convention: keep `confidence` = current metadata score (do not rename, avoids breaking dashboards). Add `ml_score`, `ml_model`, `ml_model_version`, `ml_decision`, `ml_reason`, `ml_decided_at`. This dual-score layout lets the Quality page A/B compare.

---

## 3. First-step feature engineering

All features are **numeric, deterministic, and cheap**. They become the columns of the decision feature vector.

### 3.1 Title lexical (four views + normalizations)

| Feature | Implementation | Why |
|---|---|---|
| `title_levenshtein_norm` | `1 - levenshtein(a,b) / max(len(a),len(b))` on normalized titles | captures typos/romanization differences |
| `title_jaro_winkler` | reuse `MetadataFuzzyStrategy::jaroWinkler` | prefix-sensitive, good for titles |
| `title_ngram_cosine` | cosine of char 3-gram tf vectors | robust to word order / spacing |
| `title_token_sort_ratio` | sort tokens, then ratio of matching token runs | “no game no life” vs “no life no game” |
| `title_containment` | `min(len)/max(len)` if substring else 0 | sequel/dub naming (“Movie”, “TV”) |
| `title_synonym_overlap` | binary 0/1 (existing) but expose as feature | lets model weight it instead of fixed 0.9 |
| `title_exact_norm` | boolean equality of `normalizeTitle` | strong positive signal |

N-gram cosine pseudocode:

```php
public static function ngramCosine(string $a, string $b, int $n = 3): float
{
    $va = self::ngramTf($a, $n);
    $vb = self::ngramTf($b, $n);
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    foreach (array_merge(array_keys($va), array_keys($vb)) as $g) {
        $x = $va[$g] ?? 0; $y = $vb[$g] ?? 0;
        $dot += $x * $y; $na += $x * $x; $nb += $y * $y;
    }
    return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
}
```

All string inputs use the already-stored `title` and the filtered string synonyms (`array_values(array_filter($syn, 'is_string'))`).

### 3.2 Structured match features (already weighted; re-expose as 0/1)

- `type_match` = `(string)$a['type'] === (string)$b['type']`
- `episodes_match` = exact equality of `episodes`/`chapters`
- `status_match` = exact equality
- `year_exact`, `year_diff` (clipped 0–5), `year_similarity` (reuse `yearSimilarity`)
- `season_match` (enum equality)
- `source_ref_overlap` = any shared `reference_ids` key between the two entries (strong positive, often gold)

### 3.3 Embeddings: multilingual title synonyms

Add a single `embedding_cosine` feature computed over a **text blob**:

```
blob = title + " | " + implode(" | ", string synonyms) + " | " + type
```

Rationale: title alone loses synonym context; type disambiguates homonyms (“Naruto” movie vs TV).

Embeddings model shortlist (local, CPU, PHP-invoked):

| Model | Dim | Size | Languages | Notes |
|---|---|---|---|---|
| `paraphrase-multilingual-MiniLM-L12-v2` | 384 | ~118M | 50+ incl. JA/KO | Good default; sentence-transformers |
| `sentence-transformers/LaBSE` | 768 | ~471M | 109 | Stronger cross-lingual, 2x cost |
| `all-MiniLM-L6-v2` | 384 | ~22M | English only | Fast baseline for EN-heavy subset |

Recommend starting with `paraphrase-multilingual-MiniLM-L12-v2`. Normalize vectors at encode time so similarity is a dot product.

### 3.4 Tag / genre overlap via cross-source taxonomy

Today `tagsSimilarity` is Jaccard on raw source strings → ~0. Build a **canonical tag layer**:

1. **Normalize** (lowercase, trim, ASCII-fold romaji where trivial).
2. **Alias map** `storage/state/tag-aliases.json`: source tag → canonical tag id. Seed from AniList/Kitsu/MAL genre lists (a small curated file, ~200–500 entries).
3. **Unmapped tags**: embed each unique tag with the same MiniLM model and map to nearest canonical tag above cosine `0.82`; below threshold leave as `_raw:<hash>` (not counted).
4. Store canonical tags on entries as `tags_canonical: string[]` (migration/backfill) OR compute a runtime map used by feature extraction. Prefer backfill at combine time into the merged doc and a side table for sources.

Then expose:

- `tag_canonical_jaccard`
- `tag_canonical_intersection_count`
- `genre_exact_overlap` (MAL genres vs AniList genres after canonicalization)
- `theme_overlap` (themes/tropes if present)

Expected effect: current `tags` weight can move from `1.5` to `5–8` once canonical overlap is nonzero; validate via weightage tool. Documented rule: **do not raise the tags weight until canonical overlap on 600 matched pairs is ≥ 0.15 mean**.

### 3.5 Content hash for idempotency

```
content_hash = sha256(json_encode([
  normalizedTitle, sorted(synonyms), sorted(tags_canonical),
  type, (int)episodes, status, year, season
], JSON_UNESCAPED_UNICODE))
```

Embeddings and feature rows are keyed by `(content_hash, model, model_version)`. On combine, compute the hash; if an embedding exists for that key, reuse; otherwise enqueue. This makes re-runs cheap and deterministic.

---

## 4. Decision models survey (content size, free/cheap budget)

### 4.1 Sentence-transformers sidecar (recommended first)

- Local Python, no API cost. Invoked from PHP (§6).
- Memory: MiniLM-L12 ~500 MB RSS at runtime; LaBSE ~1.6 GB.
- Throughput guidance (CPU, batch size 64): tens of thousands of short title blobs per minute on a modern laptop; **measure on your machine before committing a budget**; cache makes steady-state negligible.

### 4.2 LLM-as-judge (cheap, only for the ambiguous band)

Use only when the local model + weighted score is **inconclusive**, defined as final `ml_score` (or, before ML, `confidence`) in the **70–85** band, and the pair is a top-1 candidate.

- Providers: OpenAI `gpt-4o-mini` or Anthropic `claude-3-5-haiku`. Typical cost **~$0.0005–$0.003 per pair** depending on prompt size and provider.
- Input: compact JSON of both entries: `{title, synonyms[], type, episodes/chapters, status, year, season, tags_canonical[], source}`. Output strict JSON: `{"match": bool, "confidence": 0-100, "reason": "≤ 120 chars"}`.
- Hard caps: `MAX_LLM_PAIRS_PER_RUN` (default 2000), `LLM_BUDGET_USD` (default 5.00). When capped, skip remaining ambiguous pairs (keep weighted decision) and log `llm_skipped_budget`.
- Cache key: `sha256(json_encode([a,b,model,prompt_version]))` → store in `llm_judgements` collection. Re-runs are free.
- Privacy: only titles + metadata are sent; no user data, no URLs with tokens.

Illustrative run cost: if 4,000 ambiguous pairs are judged at $0.001 average → **~$4/run**. With content-hash caching, repeated runs of unchanged data cost $0.

### 4.3 XGBoost / logistic regression over extracted pairs

Once we have ≥ ~2,000 labeled pairs, train a classifier **over the feature vector from §3** (not over raw text):

- Labels: approve (1) / reject (0) from HITL + high-confidence auto-labels + LLM bootstrap. Keep a separate `unsure` class excluded from training, or treat as soft label 0.5 if the learner supports it.
- Features: all §3 features + `embedding_cosine`.
- Models: start with logistic regression (interpretable, CalibratedClassifierCV) for v2.0; evaluate XGBoost in v2.1 against the same feature set.
- Calibration: isotonic/Platt so the output maps to the `0–100` `ml_score` and the threshold is meaningful. Uncalibrated boosting scores are fine for ranking but bad for thresholds.
- Evaluation: stratified 5-fold, report precision/recall/F1 at threshold 70, ROC-AUC, and confusion matrix. Must beat `metadata_fuzzy` confidence baseline on a fixed hold-out set to ship.

DBSCAN (optional helper): cluster normalized title embeddings per `(type, year)` to find duplicate *groups* larger than pairs. Useful for “same franchise, many versions” (TV/movie/OVA). Apply DBSCAN with `eps` ~0.15 cosine distance on title embeddings; groups become a review queue, not an auto-merge. Keep this behind a flag until labeled data exists; unsupervised clustering alone tends to over-merge sequels.

### 4.4 Clef/JEB-style decision model (reference, no invented specifics)

“CLEF/JEB decision model” here means: an **external, versioned classifier trained on (candidate-pair, label)** that receives a feature/embedding vector and returns approve/merge/reject, instead of a hand-written weighted sum.

- Pattern Hiyori already approximates internally: `metadata_fuzzy` computes a fixed weighted sum over scalar features. The next step is to **learn the weighting + interactions** from labels.
- Reference benchmark spirit: external embeddings are computed for each entry and scoring is done outside the core service. Hiyori can do the same by exporting a **feature JSONL** (`pair_id, base_id, cand_id, features{}, label, model_version`) and scoring it with the Python sidecar or any model, then importing scores.
- Clef/JEB original project specifics are not reproduced here beyond this pattern; treat any “JEB” comparison as a local benchmark harness over the same feature JSONL, not as a dependency. The original Jikan project's JEB external benchmark uses external embeddings for scoring; Hiyori can do similarly by exporting vectors and scoring externally.

### 4.5 Plugging a model into the existing `metadata_fuzzy` pipeline

Do not replace `metadata_fuzzy` in v2.0. Layer ML **after** candidate generation:

```
// Shipped (Level-0.5): MetadataMlStrategy
candidates = MetadataFuzzyStrategy::candidates(db, table, base)   // same chain as metadata_fuzzy
foreach candidate:
    skip if MetadataFuzzyStrategy::isAnchorRejected(base, candidate)     // hard veto
    meta = MetadataFuzzyStrategy::calculateScore(base, candidate, weights)
    skip if meta < metadata_floor                                        // cheap gate
    features  = FeatureExtractor::extract(base, candidate)               // 12-dim, [0,1]
    mlScore   = LogisticModel::predict(features)                         // 0..1
keep highest mlScore >= ml_threshold
merge(... extra: confidence, ml_score, ml_model, ml_model_version, ml_decision, ml_decided_at)
```

Shipped commands: `php hiyori ml:export-pairs` (labels each base↔candidate pair positive iff the
`organized` ground truth carries both reference ids) → `php hiyori ml:train` (writes
`storage/state/ml-model.json`, reports precision/recall/F1/AUC) → `php hiyori combine <base>
--strategy metadata_ml --config '{"ml_threshold":0.6,"metadata_floor":55}'`. A missing model is a
hard error (never a silent fallback). `ml_reason` and isotonic calibration are still pending.

Rationale: keeps the proven coverage (10,770 merges, 0 duplicate upsert keys) while improving borderline decisions and adding audit metadata. `metadata_ml` stays opt-in (not the default) until the F1 gate in §10 passes.

---

## 5. Decision logic (multiclass, with rejection buffer)

```
function decide(features, mlScore?, llm?) -> {decision: approve|reject|unsure, reason}
  if features.source_ref_overlap:            return approve (refs agree)
  if weightedConfidence >= 90:                 return approve (high confidence)
  if 70 <= weightedConfidence < 90:
      if mlScore !== null:
          if mlScore >= 75:                    return approve
          if mlScore <= 45:                    return reject
      if llm available and ambiguity (70..85 band):
          return llm.match ? approve : reject   // llm is tie-break only
      return unsure                           // store in rejection buffer
  return reject
```

Labels persisted as **multiclass**:

| `ml_decision` | Meaning | Used for |
|---|---|---|
| `approve` | merged | positive training sample |
| `reject` | candidate considered, not merged | negative training sample |
| `unsure` | borderline, no decision (held) | review queue / human labeling |

Rejection buffer = collection `pair_rejections` with `{base_ref, cand_ref, features, weighted_confidence, ml_score, llm_verdict, created_at, resolved_by}`. Promoting a row (human agrees/disagrees) updates `_labels` collection with `label`, `labeler`, `label_ts` and feeds the next training run. This closes the HITL loop already exposed by `/api/sample`.

LLM is **never** the sole approver above the high-confidence bar, and **never** overrides source-ref agreement.

---

## 6. PHP ↔ Python bridge options

### Option A — file-based batch (recommended first)

No long-running service, no sockets, no PHP extensions beyond proc functions.

```
storage/tmp/ml-in-<run>.jsonl   (one JSON per line: {id, text, kind})
storage/tmp/ml-out-<run>.jsonl  (one JSON per line: {id, vector: float[]})
```

PHP:

```php
$in  = storagePath('tmp/ml-in-' . $run . '.jsonl');
$out = storagePath('tmp/ml-out-' . $run . '.jsonl');
file_put_contents($in, implode("\n", array_map('json_encode', $rows)) . "\n");

$cmd = sprintf(
    'python3 %s --model %s --in %s --out %s --batch 64',
    escapeshellarg(basePath('scripts/embed.py')),
    escapeshellarg($model),
    escapeshellarg($in),
    escapeshellarg($out),
);
exec($cmd, $stdout, $rc);
if ($rc !== 0) { throw new RuntimeException("embed sidecar failed: " . implode("\n", $stdout)); }
foreach (file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    upsertEmbedding($r['id'], $model, $r['vector'], $r['content_hash']);
}
```

`scripts/embed.py` (minimal):

```python
import argparse, json
from sentence_transformers import SentenceTransformer
ap = argparse.ArgumentParser()
ap.add_argument('--model', required=True)
ap.add_argument('--in', dest='inp', required=True)
ap.add_argument('--out', required=True)
ap.add_argument('--batch', type=int, default=64)
a = ap.parse_args()
model = SentenceTransformer(a.model)
ids, texts, hashes = [], [], []
with open(a.inp) as f:
    for line in f:
        o = json.loads(line)
        ids.append(o['id']); texts.append(o['text']); hashes.append(o['content_hash'])
vecs = model.encode(texts, batch_size=a.batch, normalize_embeddings=True, show_progress_bar=False)
with open(a.out, 'w') as f:
    for i, v in enumerate(vecs):
        f.write(json.dumps({'id': ids[i], 'content_hash': hashes[i], 'vector': v.tolist()}) + "\n")
```

### Option B — direct OpenAI/Anthropic API from PHP (for LLM judge only)

Use `file_get_contents`/cURL with the key from env. No Python needed for the judge. Keep requests to batches of 10–20 pairs per call to reduce overhead; enforce the caps from §4.2. JSON mode / tool calling forces the output schema.

### Option C — long-running Python microservice (later)

FastAPI service exposing `/embed` and `/score`. Only revisit if per-run proc_open overhead becomes measurable (target: < 2s spawn; if exceeded, switch). Keep Option A contract as the API shape so the sidecar is swappable.

Operational notes:
- Python venv at `~/.venvs/hiyori-ml` (or `storage/.venv`). Documented in setup.
- Pin `sentence-transformers` and model revisions in a `requirements.txt` with hashes.
- Handle non-zero exit and partial output: treat as run failure, do not partially write organized.
- Concurrency: single proc per run; parallel runs lock via existing `LockableTrait`.

---

## 7. Storage & migration (v4 — proposed)

Migrator v4 (name `ml_fields_and_indexes`), idempotent, additive only:

```php
4 => ['name' => 'ml_fields_and_indexes', 'run' => fn () => $this->applyMlIndexes()],
```

Collections/indexes:

- `organized` / `organized_manga`: indexes on `ml_model`, `ml_decision`, `content_hash`. (Fields are schemaless.)
- `embeddings`: `{_id: {hash, model, version}, vector, dim, created_at}`; unique index.
- `llm_judgements`: `{_id: pair_hash, model, verdict, confidence, reason, created_at}`.
- `pair_rejections`: index on `created_at`, `ml_decision`.
- `_labels`: `{pair_id, label, labeler, label_ts, model_version}` (HITL outcomes).

No migration is strictly required to *store* fields (Mongo is schemaless), but indexes keep Quality-page filtering fast and make idempotent upserts possible.

Content-hash idempotency rule: embeddings/vectors are recomputed when the hash or the model/version changes; otherwise reused. Combined with `mergeIntoOrganized` upsert on `reference_ids`, a full re-run produces identical `confidence`/`ml_score` values (regression test).

---

## 8. Dashboard integration (Quality page + Explorer)

- **Explorer columns**: `confidence`, `ml_score`, `ml_model`, `ml_model_version`, `ml_decision` (opt-in, default hidden like tags/synopsis).
- **Model + score columns**: show `ml_score` next to `confidence` in any document drawer and the merge sampler view.
- **Score histograms**: `/api/histogram` generalized to take `?field=confidence|ml_score|score`; keep default `confidence`. Quality page renders two 10-bin charts (before/after ML) plus a cumulative-merges curve for threshold selection.
- **Threshold suggestions**: for each candidate threshold, show expected merge count and (if labels exist) precision/recall/F1. Recommend but do not auto-apply; surface as a suggested value in Settings → Combine.
- **Rejection buffer view**: paginated `pair_rejections` with filters; “agree/disagree” promotes to `_labels` (reuses `/api/sample` agree/disagree semantics).
- **Model/version filter**: filter organized docs by `ml_model_version` for A/B review after a model swap.
- **Config UI** (Settings → Combine/ML): model name, sidecar path, `MAX_LLM_PAIRS_PER_RUN`, `LLM_BUDGET_USD`, enable ML by default toggle. Backed by `.env` + `storage/state/ui.json` like rate limits.

---

## 9. Cost estimates (budget-planning)

| Component | Cost | Runtime (rough) | Notes |
|---|---|---|---|
| MiniLM-L12 embeddings (first run) | $0 | order of tens of minutes for ~50k title blobs on CPU | cached after |
| MiniLM-L12 embeddings (steady state) | $0 | < 1 min | only new/changed titles |
| LaBSE (upgrade) | $0 | ~2–4× MiniLM | opt-in |
| LLM judge, 4,000 pairs @ $0.001 | ~$4 | API-bound, minutes | capped per run |
| XGBoost/logistic train | $0 | seconds–minutes on a few thousand pairs | offline Python |
| Storage (vectors 384×float32×50k) | ~75 MB | — | Mongo; compress not needed |
| Dashboard serving | unchanged | — | no new infra |

Recommend a **default monthly budget of $10–$20** with the caps; the judge only touches the 70–85 band, so common runs are cheap. Track spend in telemetry (`llm_tokens`, `llm_cost_usd`) for the Management page.

---

## 10. Expected gains (hypotheses to validate, not promises)

| Change | Expected effect | How to measure |
|---|---|---|
| Multilingual embeddings | +5–15% recall on JA/KO-heavy titles; fewer missed cross-language synonyms | hold-out label F1; organized count delta on manga (where ref coverage is lower) |
| Canonical tags | tags weight 1.5 → 5–8, + small recall boost | mean canonical overlap on 600 matched pairs; weightage discrimination gap |
| LLM tie-break in 70–85 band | fewer false positives among the 3,705 `70–80` merges; precision ↑ | false-merge sample review (n=200) by a human |
| Learned model vs weighted sum | F1 at threshold 70 ≥ baseline +2 pts before promotion | offline benchmark + identical hold-out |
| Idempotency (content hash) | re-runs produce identical scores (regression) | CI test |

Shipping gate: `metadata_ml` only becomes default if, on a fixed labeled hold-out, it beats `metadata_fuzzy` on F1 at threshold 70 **and** shows no regression in duplicate upsert keys (must stay 0, as in benchmark v2).

Harness: `php hiyori combine:benchmark` sweeps thresholds for both scorers over a labelled JSONL and prints a PASS/FAIL gate line; it is non-destructive (no MongoDB). The F1 margin is configurable (`--margin <points>`) and `--strict` exits non-zero when the gate is not met, so CI can enforce "F1 ≥ baseline + margin". The duplicate-key half of the gate is `php hiyori combine:dupes` (fails when a combined collection repeats an identity key such as `mal`/`al`; franchise keys like `k`/`cr`/`t` may legitimately repeat). For an honest gate use `ml:export-pairs --holdout 30`, which writes a deterministic, **group-safe** `-train`/`-holdout` split (all rows for a `base_source:base_id` land on one side, so no leakage), then `ml:train --in pairs-*-train.jsonl` and `combine:benchmark --in pairs-*-holdout.jsonl`. Benchmarking the training file is in-sample and will overstate the ML score.

---

## 11. Rollout plan (phased)

1. **P0 — Baseline & labels (no model change).** ✅ `/api/sample` agree/disagree (Quality page) records `_labels` via `POST /api/labels` (also `php hiyori labels add`); `ml:export-pairs` prefers human labels over the organized ground truth. Feature snapshot per label + the ≥1,000-pair target are still open.
2. **P1 — Python sidecar embeddings + cache.** Implement Option A, `embeddings` collection, content-hash idempotency. Stamp `title_embedding_model/version` only (no decisions).
3. **P2 — Feature extractor + offline eval.** ✅ Implemented in PHP: `FeatureExtractor`, `ml:export-pairs` (labelled JSONL), `ml:train` (pure-PHP logistic regression with precision/recall/F1/AUC reporting).
4. **P3 — LLM judge (capped) for 70–85 band**, caching by pair hash. Store `llm_judgements`.
5. **P4 — `metadata_ml` strategy** ✅ implemented as an opt-in second-stage scorer (`--strategy metadata_ml`) storing `ml_*` fields; still to prove out: benchmark v3 (anime + manga) before any default switch.
6. **P5 — Dashboard (Batch 4 §4.6)** and, if gates pass, promote `metadata_ml` to default.

---

## 12. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Embeddings over-merge same-named remakes | Structured features (year/episodes/type) dominate in practice; model only adds; keep threshold; human review for sequel clusters |
| LLM hallucination / inconsistency | JSON schema + low temperature; LLM is tie-break only, never sole approver; cache prevents drift between runs |
| Budget overrun | Hard caps + budget guard; default off for judges; telemetry for spend |
| Python environment drift | Pinned `requirements.txt`, venv documented, sidecar smoke test in CI |
| Model drift between runs | Version every artifact (model, prompt, code) as `ml_model_version`; filterable in dashboard |
| Latency of combine (bench v2 already 1,429 s) | Cache dominates; embeddings batched offline; LLM cap; candidate narrowing unchanged |
| Privacy of titles (fine) | Only metadata sent; no user identifiers |

---

## 13. Open questions

1. Pick the **embedding model** via an offline F1 shootout on the labeled hold-out, not by parameter count.
2. Is **LaBSE** (768-dim) worth 2–4× CPU for JA/KO titles? Measure with the hold-out before deciding.
3. Store canonical tags on source docs or compute at combine time? (Recommend combine time for P1, backfill in P4.)
4. Replace logistic regression with **XGBoost** in v2.1 only if calibration (isotonic) keeps threshold meaningful.

---

## Appendix A — End-to-end feature vector (one row)

```json
{
  "pair_id": "al:12345|mal:54321",
  "base_kind": "anime",
  "title_levenshtein_norm": 0.74,
  "title_jaro_winkler": 0.91,
  "title_ngram_cosine": 0.83,
  "title_token_sort_ratio": 0.78,
  "title_containment": 0.0,
  "title_synonym_overlap": 0.0,
  "title_exact_norm": 0.0,
  "type_match": 1,
  "episodes_match": 1,
  "status_match": 1,
  "year_exact": 1,
  "year_diff": 0,
  "year_similarity": 1.0,
  "season_match": 1,
  "tag_canonical_jaccard": 0.25,
  "tag_canonical_intersection_count": 2,
  "genre_exact_overlap": 1,
  "source_ref_overlap": 0,
  "embedding_cosine": 0.88,
  "weighted_confidence": 78.2,
  "label": "approve"
}
```

## Appendix B — Example hybrid score mixture

A pair in the ambiguous band, combined as `final = 0.6*weighted_confidence + 0.4*mlScore` for ranking (decision still uses §5, not the mixture):

| Component | Value |
|---|---|
| weighted_confidence (metadata_fuzzy) | 76.1 |
| embedding_cosine | 0.86 → 86.0 |
| tag_canonical_jaccard | 0.30 → 30.0 |
| `mlScore` (logistic, calibrated) | 81.4 |
| LLM judge (only if 70–85) | match=true, 84 |
| **Decision** | approve (weighted≥70, mlScore≥75; LLM agrees) |

This illustrates the intended “embeddings lift a borderline title pair above the bar while LLM provides an audit trail.”

## Appendix C — Suggested `.env` additions

```
ML_MODEL=paraphrase-multilingual-MiniLM-L12-v2
ML_SIDECAR_PYTHON=python3
ML_SIDECAR_SCRIPT=scripts/embed.py
ML_SIDECAR_VENV=
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
LLM_JUDGE_MODEL=gpt-4o-mini
LLM_JUDGE_MAX_PAIRS=2000
LLM_JUDGE_BUDGET_USD=5.00
ML_DEFAULT_ENABLED=false
```

All keys are validated/masked by the existing Environment manager.
