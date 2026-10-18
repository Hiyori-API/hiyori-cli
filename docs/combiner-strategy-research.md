# Hiyori CLI — Combiner Strategy Research

**Status:** Implemented in `src/Service/Combiner/Strategy/{GraphMergeStrategy,FellegiSunterStrategy,SemanticRerankStrategy}.php` and registered in `StrategyConfigurationFactory`; this document remains the design/reference record.
**Primary references:** `src/Service/Combiner/Combiner.php`, `src/Service/Combiner/Strategy/*`, `src/Service/StrategyConfigurationFactory.php`, `src/Helper.php`, `src/Service/Exporter.php`, `src/Service/Telemetry.php`, `docs/ml-augmentation-design.md`, `docs/release-pipeline-design.md`.

---

## Executive summary

The combiner currently offers three strategies:

1. `relational_mapping` — exact shared-reference joins (strict, high precision, low recall when reference IDs are missing).
2. `relational_fuzzy` — first-hop reference-ID overlap between entries, again no metadata scoring.
3. `metadata_fuzzy` — cross-source weighted metadata similarity (default; benchmark winner).

`metadata_fuzzy` carries the production load because it has tunable weights (`title`, `type`, `episodes`, `status`, `season`, `year`, `tags`, `synonyms`) and a single threshold (default `70`). It already emits per-merge `confidence` and a telemetry histogram, which makes it the natural substrate for the ML layer.

The principal research findings:

- **Failure is concentrated in three areas:** (1) title normalization collisions/misses, especially romanization and script variants; (2) containment scoring that over-credits short/shared tokens (`"Movie"`, `"TV"`), leading to false merges of sequels/editions; (3) no anchor-field rejection, so high title overlap can override clear `type`/`episodes`/`year` disagreement.
- **Tuning is threshold-dominated.** The existing confidence distribution (from shipped benchmark: `70–80 = 3,705`, `80–90 = 680`, `90–100 = 6,385` of `10,770` merges) means a move from `70` to `75` or `80` directly trades ~thousands of merges against precision. The required evaluation is a labeled precision/recall sweep at `65/70/75/80`, not a single number.
- **High-value, low-risk improvements** are candidate-filter tightening, Unicode-safe title normalization, and explicit anchor-field rejection *before* score computation. These can ship behind config flags with no schema changes.
- **Three stronger strategies** are proposed as optional, reversible additions: graph connected components (edge-based, composite-doc confidence), Fellegi–Sunter probabilistic record linkage (m/u calibration per field), and semantic candidate re-ranking via embeddings/LLM, which is the natural next step of `docs/ml-augmentation-design.md`.
- **Default behavior must remain `metadata_fuzzy`** until labeled A/B evidence shows F1 ≥ baseline + agreed margin. All new strategies register in `StrategyConfigurationFactory::AVAILABLE_STRATEGIES` but are opt-in.

---

## 1. Baseline analysis of existing strategies (src code references)

### 1.1 Execution path

`Combiner::start()` in `src/Service/Combiner/Combiner.php` prints the active strategy NAME and base source, then dispatches:

```php
call_user_func($this->strategy->get()::class . '::process',
    $this->db, $this->console, $this->strategy, $this->source);
```

All strategies share the static signature:

```php
public static function process(
    Database $db,
    ConsoleFactory $console,
    StrategyConfigurationFactory $strategy,
    SourceConfigurationFactory $source
): void;
```

Registration lives in `src/Service/StrategyConfigurationFactory.php`:

```php
public const AVAILABLE_STRATEGIES = [
    RelationalMappingStrategy::class,
    RelationalFuzzyStrategy::class,
    MetadataFuzzyStrategy::class,
];
```

Selectability is by `::NAME` (`relational_mapping`, `relational_fuzzy`, `metadata_fuzzy`). Strategy config is read via `$strategy->getConfig('weights'|'threshold'|'collection')`.

### 1.2 `metadata_fuzzy` (default)

File: `src/Service/Combiner/Strategy/MetadataFuzzyStrategy.php`.

Key symbols:

- `DEFAULT_WEIGHTS`:
  - `title 26.4`, `synonyms 0`, `type 5.7`, `episodes 26.2`, `status 0.6`, `season 16.4`, `year 23.2`, `tags 1.5`.
  - Comment: optimized on `600` matched MAL↔AniList pairs; “Tags intentionally near-zero: different sources use disjoint tag sets.”
- `process()`: reads weights/threshold/collection, iterates base collection, for every available non-base source calls `findBestMatch()`, merges via `Helper::mergeEntries()`, allocates `hiyori_id`, writes via `Database::mergeIntoOrganized()` with `extra = ['confidence' => score, 'hiyori_id' => ...]`, then emits `Telemetry::update('combine-latest', ...)`.
- `findBestMatch()`: progressive candidate narrowing through `candidateFilterChain()`, scores with `calculateScore()`, keeps the highest scoring candidate above threshold.
- `candidateFilterChain()`: strict `{type,status,year}`, then drops `year`, then `year,status`, then all three, then `type` only, then no filter. Limit `100` docs per filter.
- `calculateScore()`: weighted sum normalized by `array_sum($weights)`.
- `fieldSimilarity()`: dispatches by field; default compares lowercased strings with Jaro-Winkler + token Jaccard.
- `titleSimilarity()`: exact normalized equality (`1.0`), synonym intersection (`0.9`), containment (`0.75 + 0.15 * ratio`), otherwise token Jaccard.
- `normalizeTitle()`: strips everything except `[a-z0-9]` (`preg_replace('/[^a-z0-9]+/i', '', ...)`) and lowercases. This is both the collision source and the multilingual gap.
- `yearSimilarity()`: graded `1 - |dy|/5`, clamped to 0; returns `0` if either year is empty.
- `jaroWinkler()`, `tokenJaccard()`, `tagsSimilarity()` are public and reusable (used by `WeightageCommand`).

Observed characteristics:

- **Strengths:** interpretable weights, reusable scoring primitives, progressive relaxation avoids zero candidates, per-merge confidence stored, benchmark history.
- **Weaknesses:** title normalization is ASCII/spacing-destructive; synonym information is binary (`0.9/0`); tags carry near-zero weight; containment has a floor that can promote wrong editions; threshold is global and not calibrated per entity kind (anime vs manga); candidate filter fallback to `[]` can scan whole small collections; best-match is greedy per source, not globally consistent.

### 1.3 `relational_mapping`

File: `src/Service/Combiner/Strategy/RelationalMappingStrategy.php`.

- Joins only when `Database::exists(targetTable, targetShorthand, baseId)` where `baseId = Helper::resolveReferenceId(entry, baseShorthand)`.
- On hit, fetches the matched entry and merges; no score, no threshold, no confidence field in `extra` (only `hiyori_id`).
- Telemetry carries only `merges`.

Characteristics:

- **Precision:** very high when IDs are present and correct.
- **Recall:** bounded by ID coverage; manga entries use `al`/`k`/`mal` while config uses `al_manga` etc. `Helper::resolveReferenceId()` compensates by stripping `_manga`.
- **Risk:** does not validate title/type/year; an incorrectly sourced ID propagates unchallenged. No confidence for downstream quality gates.

### 1.4 `relational_fuzzy`

File: `src/Service/Combiner/Strategy/RelationalFuzzyStrategy.php`.

- For each entry, iterates `reference_ids` and checks if any `(identifier, value)` exists in the target source.
- Requires the matched entry to share the same `(identifier, value)` in its own `reference_ids`.
- Merges on first match and `break`s.

Characteristics:

- Bridges sources that share an ID namespace even when the base source lacked its own ID (`baseId` still required for organized upsert).
- Still no metadata validation or score; a shared legacy ID can join unrelated source rows if ID namespaces collide.
- Break-after-first-match can starve a record of additional source references when multiple IDs are available.

### 1.5 Shared helpers and integration points

`src/Helper.php`:

- `resolveReferenceId(array $entryArray, string $shorthand): string` — shorthand then `_manga` fallback.
- `mergeEntries(string $table, array $entry, array $mergeEntry): Base` — picks `MangaBase` vs `AnimeBase`.
- `convertMongoResultToArray()` — BSON normalization.

`src/Service/Exporter.php`:

- `referenceIds(array $doc): array` — canonical ordered `reference_ids` view used in export and slug links.
- `slugify()` — title slug used for `hiyori_id` allocation and entry filenames.

`src/Service/Telemetry.php`:

- `update(string $key, array $data)` — merges into `storage/state/<key>.json` and stamps `updated_at`.
- `append()`/`tail()` available for event streams, not currently used by strategies.

The ML design (`docs/ml-augmentation-design.md`) already defines the intended storage convention: keep `confidence` untouched and add `ml_score`, `ml_model`, `ml_model_version`, `ml_decision`, `ml_reason`, `ml_decided_at`.

---

## 2. Failure modes and tuning grids

### 2.1 Failure-mode catalog

| ID | Strategy | Failure | Evidence in code | Impact |
|---|---|---|---|---|
| F1 | metadata_fuzzy | Containment floor merges sequels/dubs | `titleSimilarity()` returns `0.75 + 0.15*ratio` for substring titles | False merge of `"X Movie"` into `"X"`, `"X: Part 2"` into `"X"` |
| F2 | metadata_fuzzy | Multilingual/script misses | `normalizeTitle()` strips all non-`[a-z0-9]` | Romaji/JA/KO and punctuation-variant titles under-score; reliance on synonyms binary 0.9/0.0 |
| F3 | metadata_fuzzy | Anchor fields ignored when title strong | `calculateScore()` sums all weighted fields; no veto | `"Naruto"` movie vs TV can merge if title/season/year align |
| F4 | metadata_fuzzy | Tags effectively unused | `DEFAULT_WEIGHTS['tags'] = 1.5`, empty overlap | No genre/theme signal; weightage uninformative |
| F5 | metadata_fuzzy | Greedy per-source best match | `bestMatch` loop chooses max score; no global consistency | Conflicting joins collapse distinct editions under one organized doc |
| F6 | metadata_fuzzy | Threshold cliff | single global float `70.0` | `70–80` holds 3,705 merges (34%) with no calibrated band |
| F7 | metadata_fuzzy | Candidate scans can blow up | fallback filter `[]` with limit 100 | Low precision on tiny/unfiltered collections; wasted work |
| F8 | relational_mapping | No validation of metadata | only ID existence | Bad IDs propagate; no confidence |
| F9 | relational_fuzzy | First-match break | `break` after first ID hit | Misses additional source refs; ID collisions join wrong rows |
| F10 | all | No persistent precision/recall | strategies emit merge counts only | Cannot justify threshold or weight changes |
| F11 | all | Greedy upsert key | `resolveReferenceId` + `mergeIntoOrganized` | Empty/blank IDs can collapse unrelated rows onto one key (mitigated but still risky for new sources) |
| F12 | all | Manga vs anime not separated in strategy | only collection string switch | Kind-specific anchor tolerances (chapters vs episodes) not modeled |

### 2.2 Tuning grid (required thresholds)

Evaluation protocol for each threshold `T ∈ {65, 70, 75, 80}`:

- Fix sources + snapshot (e.g. anilist base vs myanimelist/kitsu).
- Generate candidate pairs at `T - 5` to widen recall.
- Human labels (or gold `reference_ids` overlap for auto mode): `match`, `non-match`, `uncertain`.
- Compute: precision `TP/(TP+FP)`, recall `TP/(TP+FN)`, F1, merges emitted, median confidence, and `merge_keys` cardinality (duplicate organized keys = bad).
- Release gate: F1 ≥ baseline and duplicate keys = 0. Suggested acceptance: precision ≥ 0.97 for default path.

Baseline distribution (shipped, anime, `organized = 10,770`):

| Band | Merges |
|---|---|
| 70–80 | 3,705 (34.4%) |
| 80–90 | 680 (6.3%) |
| 90–100 | 6,385 (59.3%) |

Tuning template to populate via benchmark (no numbers fabricated):

| Threshold | Expected merges | Precision | Recall | F1 | Duplicate keys | Notes |
|---|---|---|---|---|---|---|
| 65 | higher | to measure | to measure | to measure | must be 0 | exploratory; expect recall gain, FP risk |
| 70 | baseline | baseline | baseline | baseline | 0 | current default |
| 75 | lower | to measure | to measure | to measure | 0 | likely +precision, −recall |
| 80 | much lower | to measure | to measure | to measure | 0 | precision candidate for “safe” pipeline |

Interpretation guidance:

- If `80` precision barely improves while recall drops materially, keep `70` and add anchor rejection instead.
- If `70→65` adds many labels-confirmed pairs, implement candidate re-ranking rather than lowering the global threshold.
- Threshold should eventually become **band-based**: auto-merge ≥ 90, review 75–89, reject < 75 unless relational evidence exists.

### 2.3 Instrumentation prerequisite

Before any threshold change, persist per-run evaluation in `Telemetry::update('combine-latest', ...)`, plus a new append-only `combine-benchmark.jsonl` via `Telemetry::append()` containing threshold, precision, recall, F1, sample size, labels hash, completed_at. This is required for reversible rollout and A/B comparison.

---

## 3. Candidate strategy improvements (shared, low risk)

### 3.1 Title normalization upgrade

Proposed `Helper` additions:

```php
public static function normalizeTitleUnicode(?string $title): string
{
    if ($title === null) {
        return '';
    }
    // NFKC fold is best applied in PHP intl when available; fall back to regex.
    $t = class_exists(\Normalizer::class)
        ? \Normalizer::normalize((string) $title, \Normalizer::FORM_KC)
        : (string) $title;

    $t = mb_strtolower(trim($t));
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? '';
    $t = preg_replace('/\s+/u', ' ', trim($t)) ?? '';

    return $t;
}

public static function titleTokenSet(string $title): array
{
    return array_values(array_unique(preg_split('/\s+/u', self::normalizeTitleUnicode($title), -1, PREG_SPLIT_NO_EMPTY) ?: []));
}
```

`MetadataFuzzyStrategy` changes (behind config `normalize_title_unicode`, default false initially):

- Use normalized unicode title for exact equality and Jaccard.
- Keep legacy `normalizeTitle()` for backward-compatible hash/IDs if needed.
- Add `title_normalized` to merged doc via `$extra` only if opted in (migration-safe; optional field).

Rationale: preserves token boundaries, avoids `"cowboybebop"` collisions, while leaving existing normalized-hash consumers working.

### 3.2 Anchor-field rejection

Add a veto before score computation:

```php
private static function anchorMismatch(array $e1, array $e2, array $config): bool
{
    $tolYear = (int) ($config['anchor_year_tolerance'] ?? 1);
    $allowStatusMismatch = (bool) ($config['allow_status_mismatch'] ?? true);

    if (!empty($e1['type']) && !empty($e2['type'])
        && strtolower((string) $e1['type']) !== strtolower((string) $e2['type'])) {
        return true;
    }

    if (isset($e1['episodes'], $e2['episodes'])
        && (int) $e1['episodes'] !== (int) $e2['episodes']
        && (int) $e1['episodes'] > 0 && (int) $e2['episodes'] > 0) {
        return true;
    }

    $y1 = (int) ($e1['year'] ?? 0);
    $y2 = (int) ($e2['year'] ?? 0);
    if ($y1 > 0 && $y2 > 0 && abs($y1 - $y2) > $tolYear) {
        return true;
    }

    if (!$allowStatusMismatch && !empty($e1['status']) && !empty($e2['status'])
        && strtolower((string) $e1['status']) !== strtolower((string) $e2['status'])) {
        return true;
    }

    return false;
}
```

Integrate in `findBestMatch()`:

```php
if (!empty($config['anchor_rejection']) && self::anchorMismatch($entry, $candidateArray, $config)) {
    continue;
}
```

Config keys: `anchor_rejection` (bool, default false), `anchor_year_tolerance` (int, default 1), `allow_status_mismatch` (bool, default true, because source status can lag).

### 3.3 Weight recalibration via benchmark/web sample

- Use existing `WeightageCommand` against fixed sample pairs (anime: anilist/mal; manga: anilist_manga/mal_manga).
- Require minimum 600 ground-truth pairs (as current default weights did).
- Accept weight changes only if:
  - precision at `70` does not decrease, and
  - recall does not drop > 1%, and
  - top discrimination gap remains sensible (`title`/`episodes`/`year`, not `status`/`tags`).
- Store calibration artifacts under `storage/state/weightage-<base>-<target>-<date>.json` and version them.
- Consider per-kind weight profiles (`weights_anime`, `weights_manga`) rather than one global vector.

### 3.4 Cluster telemetry

Current telemetry is scalar/summary only. Propose a richer, backward-compatible payload:

```php
(new \Hiyori\Service\Telemetry())->update('combine-latest', [
    'strategy' => self::NAME,
    'base' => $source->get()->getTable(),
    'merges' => $totalMerged,
    'threshold' => round($threshold, 1),
    'scores' => [...],
    'score_histogram' => [...],
    'rejected_anchor' => $rejectedAnchorCount,   // new
    'candidates_examined' => $candidatesExamined, // new
    'components' => $componentTelemetry ?? null, // new (graph strategy)
    'per_source' => $mergedSources,
    'completed_at' => date('c'),
]);
```

Also use `Telemetry::append('combine-events', ...)` for per-merge events:

```php
(new \Hiyori\Service\Telemetry())->append('combine-events', [
    'strategy' => self::NAME,
    'base' => $baseShorthand,
    'base_id' => $baseId,
    'target' => $currentSource->getTable(),
    'score' => $score,
    'anchor_rejected' => false,
    'hiyori_id' => $hiyoriId ?? null,
]);
```

This enables cluster analysis: merges per `hiyori_id`, component size distribution, re-run idempotency checks, and detection of over-merged records (components above configured size).

---

## 4. Three new proposed strategies (with PHP scaffolds)

All proposed strategies reuse the existing `process()` signature and persistence conventions. They do not alter ingestion or source schemas.

### 4.1 (a) Graph-based connected components

**Concept:** Build an undirected graph over the union of source entries. Add an edge between cross-source entries when anchor fields align and title similarity (Jaro-Winkler or normalized token Jaccard) exceeds `edge_title_threshold`. Compute connected components. Each component with ≥ 2 sources becomes one composite organized document. Component-level confidence is derived from edge weights (e.g. minimum or mean edge score), not from a single pair.

**Why:** Current strategies are pair-greedy and can create inconsistent transitive joins. Components make transitive consistency explicit and provide component size as a quality signal (large components usually indicate over-merging).

**Config keys:**
- `edge_title_threshold` (float, default 0.82)
- `anchor_year_tolerance` (int, default 1)
- `min_component_size` (int, default 2)
- `component_confidence_floor` (float, default 70.0)
- `max_component_size` (int, default 8; larger -> flag as suspicious)
- `title_metric` (string: `jaro_winkler` | `token_jaccard`, default `jaro_winkler`)

**Confidence model:**
- Edge weight `w(e) = max(titleSim, 0.5 * anchorAgreement)` clamped `[0,100]`.
- Component confidence `minEdge` (conservative, recommended default) or `meanEdge`.
- Reject component if size > `max_component_size` or confidence < floor; leave entries unmerged and record reason in telemetry.

**Helper additions (src/Helper.php):**

```php
public static function unionFind(array $nodes): array { /* return component id per node */ }

public static function edgeWeight(array $a, array $b, string $metric = 'jaro_winkler'): float
{
    $titleSim = MetadataFuzzyStrategy::jaroWinkler(
        (string) ($a['title'] ?? ''),
        (string) ($b['title'] ?? '')
    ) * 100.0;
    // anchor agreement boosts only when present
    return $titleSim;
}
```

**Scaffold: `src/Service/Combiner/Strategy/GraphComponentsStrategy.php`**

```php
<?php
declare(strict_types=1);
namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Collection;

final class GraphComponentsStrategy extends CombinerStrategy
{
    public const NAME = 'graph_components';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): void {
        $threshold = self::cfg($strategy, 'component_confidence_floor', 70.0);
        $edgeTitleThreshold = self::cfg($strategy, 'edge_title_threshold', 0.82);
        $anchorTol = self::cfg($strategy, 'anchor_year_tolerance', 1);
        $minSize = self::cfg($strategy, 'min_component_size', 2);
        $maxSize = self::cfg($strategy, 'max_component_size', 8);
        $table = (string) ($strategy->getConfig('collection') ?? 'organized');
        $baseTable = $source->get()->getTable();
        $nodes = self::collectNodes($db, $source);
        $edges = self::buildEdges($nodes, $edgeTitleThreshold, $anchorTol);
        $components = self::connectedComponents($nodes, $edges);

        $progress = $console->io()->createProgressBar(count($components));
        $progress->start();

        $mergedCount = 0;
        $telemetry = ['components' => count($components), 'merged' => 0, 'skipped_large' => 0, 'skipped_low_conf' => 0, 'edge_count' => count($edges)];

        foreach ($components as $component) {
            $progress->advance();
            if (count($component['nodes']) < $minSize) {
                continue;
            }
            if (count($component['nodes']) > $maxSize) {
                $telemetry['skipped_large']++;
                // record component for review, do not merge
                continue;
            }
            $confidence = self::componentConfidence($component, $edges);
            if ($confidence < $threshold) {
                $telemetry['skipped_low_conf']++;
                continue;
            }

            // composite entry assembled from the first node of each source,
            // then merged via Helper::mergeEntries() per additional node.
            $composite = self::assembleComposite($component);
            // persistence: reuse existing organized upsert path
            // ... mergeIntoOrganized($composite, baseShorthand, baseId, $table, ['confidence'=>$confidence, 'component_size'=>count($component['nodes'])])
            $mergedCount++;
        }

        $progress->finish();
        $console->io()->info('Graph components merge complete. Merged: ' . $mergedCount);
        (new \Hiyori\Service\Telemetry())->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $baseTable,
            'merges' => $mergedCount,
            'threshold' => round($threshold, 1),
            'components' => $telemetry,
            'completed_at' => date('c'),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private static function collectNodes(Database $db, SourceConfigurationFactory $source): array { return []; }

    /** @return array<int,array{a:int,b:int,w:float}> */
    private static function buildEdges(array $nodes, float $titleThreshold, int $anchorTol): array { return []; }

    /** @return array<int,array{nodes:array<int,int>,edges:array<int,int>}> */
    private static function connectedComponents(array $nodes, array $edges): array { return []; }

    private static function componentConfidence(array $component, array $edges): float { return 0.0; }

    private static function assembleComposite(array $component): \Hiyori\Models\Common\Base { return \Hiyori\Models\Anime\Base\AnimeBase::create([]); }

    private static function cfg(StrategyConfigurationFactory $s, string $key, mixed $default): mixed
    {
        return $s->getConfig($key) ?? $default;
    }
}
```

**Tests:** union-find correctness, edge predicate anchor rejection, component confidence min/mean, large-component skip, golden small graph snapshot.

### 4.2 (b) Probabilistic record linkage (Fellegi–Sunter)

**Concept:** Compute, for each candidate pair, per-field agreement weights using Fellegi–Sunter:

- For each field `f`, estimate `m_f = P(agreement | true match)` and `u_f = P(agreement | non-match)`.
- Field weight `w_f = log2(m_f / u_f)` for agreement, and `log2((1-m_f)/(1-u_f))` for disagreement.
- Total score `S = Σ w_f`. Classify: `S ≥ T_match` → merge, `S ≤ T_nonmatch` → reject, else review band.
- `m/u` are calibrated on labeled pairs (reuse `WeightageCommand` ground truth); store them in config.

**Why:** The existing `metadata_fuzzy` is a linearly weighted sum with hand-tuned weights. Fellegi–Sunter handles missing fields and interactions more naturally and provides a probabilistic interpretation useful for dashboards (“probability of match”).

**Config keys:**
- `m` (array per field), `u` (array per field)
- `match_threshold` (float, default 12.0 bits)
- `nonmatch_threshold` (float, default 3.0 bits)
- `review_band` (bool, default true)
- `fields` (list, default `['title','type','episodes','status','season','year','tags']`)

**Helper additions:**

```php
public static function fellegiSunterScore(array $e1, array $e2, array $m, array $u, array $fields): float
{
    $score = 0.0;
    foreach ($fields as $field) {
        $agree = self::fieldsAgree($e1, $e2, $field);
        $mf = $m[$field] ?? 0.5;
        $uf = $u[$field] ?? 0.5;
        $score += $agree
            ? log($mf / $uf, 2)
            : log((1 - $mf) / (1 - $uf), 2);
    }
    return $score;
}
```

**Scaffold: `src/Service/Combiner/Strategy/FellegiSunterStrategy.php`**

```php
<?php
declare(strict_types=1);
namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use MongoDB\Collection;

final class FellegiSunterStrategy extends CombinerStrategy
{
    public const NAME = 'fellegi_sunter';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): void {
        $m = (array) ($strategy->getConfig('m') ?? self::DEFAULT_M);
        $u = (array) ($strategy->getConfig('u') ?? self::DEFAULT_U);
        $matchT = (float) ($strategy->getConfig('match_threshold') ?? 12.0);
        $nonmatchT = (float) ($strategy->getConfig('nonmatch_threshold') ?? 3.0);
        $fields = (array) ($strategy->getConfig('fields') ?? ['title','type','episodes','status','season','year','tags']);
        $table = (string) ($strategy->getConfig('collection') ?? 'organized');

        // candidate generation reuses metadata_fuzzy candidateFilterChain
        $baseTable = $source->get()->getTable();
        $baseColl = $db->getCollection($baseTable);
        $total = $baseColl->countDocuments();
        $progress = $console->io()->createProgressBar($total);
        $progress->start();

        $mergedCount = 0;
        $reviewCount = 0;
        $rejectedCount = 0;
        $scores = [];

        foreach ($baseColl->find() as $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            foreach ($source->getSources() as $currentSource) {
                if ($currentSource->getTable() === $baseTable) {
                    continue;
                }
                $candidates = self::candidates($db, $currentSource->getTable(), $entryArray);
                $best = null;
                $bestScore = -INF;
                foreach ($candidates as $c) {
                    $s = Helper::fellegiSunterScore($entryArray, $c, $m, $u, $fields);
                    if ($s > $bestScore) {
                        $bestScore = $s;
                        $best = $c;
                    }
                }
                if ($best === null) {
                    continue;
                }
                if ($bestScore >= $matchT) {
                    // merge via Helper::mergeEntries + allocateHiyoriId + mergeIntoOrganized
                    // extra: ['confidence' => self::bitsToPercent($bestScore, $matchT), 'linkage_score' => $bestScore]
                    $mergedCount++;
                    $scores[] = $bestScore;
                } elseif ($bestScore <= $nonmatchT) {
                    $rejectedCount++;
                } else {
                    $reviewCount++;
                    // append to review queue (Telemetry::append('combine-review', ...))
                }
            }
            $progress->advance();
        }

        $progress->finish();
        $console->io()->info(sprintf('FS linkage complete. merged=%d review=%d rejected=%d', $mergedCount, $reviewCount, $rejectedCount));
        (new \Hiyori\Service\Telemetry())->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $baseTable,
            'merges' => $mergedCount,
            'review' => $reviewCount,
            'rejected' => $rejectedCount,
            'scores' => $scores !== [] ? ['min' => min($scores), 'max' => max($scores), 'avg' => array_sum($scores) / count($scores)] : null,
            'completed_at' => date('c'),
        ]);
    }

    private static function candidates(Database $db, string $table, array $entry): array { return []; }

    private static function bitsToPercent(float $bits, float $matchT): float
    {
        return round(min(100.0, max(0.0, ($bits / max(1.0, $matchT)) * 100.0)), 1);
    }

    private const DEFAULT_M = [
        'title' => 0.9, 'type' => 0.98, 'episodes' => 0.99, 'status' => 0.95,
        'season' => 0.7, 'year' => 0.85, 'tags' => 0.3,
    ];
    private const DEFAULT_U = [
        'title' => 0.05, 'type' => 0.25, 'episodes' => 0.05, 'status' => 0.5,
        'season' => 0.25, 'year' => 0.2, 'tags' => 0.05,
    ];
}
```

**Tests:** score monotonicity (true match > non-match), calibration file loading, threshold classification counts, idempotent re-run (merge keys), bits-to-percent bounds.

### 4.3 (c) LLM-assisted / transformer semantic candidate re-rank

**Concept:** Keep `metadata_fuzzy` (or any strategy) as candidate generator, then re-rank candidates with (1) embedding cosine similarity over title+synonyms+type, and (2) optional LLM tie-break for ambiguous bands. This directly extends `docs/ml-augmentation-design.md` (multilingual MiniLM, thresholds, storage convention `ml_score`, review queue).

**Outline (not full implementation; depends on Batch 4 ML infra):**

- Candidate set: top-K `metadata_fuzzy` candidates (K = 5) with score in `[review_floor, 100]`.
- Feature: `embedding_cosine` from `paraphrase-multilingual-MiniLM-L12-v2` (384-dim) over blob `title | synonyms | type`.
- Re-rank score: `0.6 * metadata_score + 0.4 * (embedding_cosine * 100)` (initial; learned later).
- LLM tie-break only when re-ranked score in `[75, 85]` and budget available; LLM returns `{decision: merge|keep_separate, reason}`.
- Persist `ml_score`, `ml_model`, `ml_model_version`, `ml_decision`, `ml_reason`, `ml_decided_at` as specified in ML design.

**Embeddings interface (Helper/Service):**

```php
interface EmbeddingsClientInterface
{
    /** @return list<float> */
    public function embed(string $text): array;

    public function cosine(array $a, array $b): float
    {
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y; $na += $x * $x; $nb += $y * $y;
        }
        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}

final class NullEmbeddingsClient implements EmbeddingsClientInterface
{
    public function embed(string $text): array { return []; }
}

final class LocalEmbeddingsSidecarClient implements EmbeddingsClientInterface
{
    public function __construct(private string $endpoint) {}
    public function embed(string $text): array
    {
        // HTTP to local python sidecar; cache by sha256(text)
        return [];
    }
}
```

**Scaffold: `src/Service/Combiner/Strategy/SemanticRerankStrategy.php`**

```php
<?php
declare(strict_types=1);
namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;

final class SemanticRerankStrategy extends CombinerStrategy
{
    public const NAME = 'semantic_rerank';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): void {
        $minConfidence = (float) ($strategy->getConfig('review_floor') ?? 70.0);
        $candidateK = (int) ($strategy->getConfig('candidate_k') ?? 5);
        $embeddingWeight = (float) ($strategy->getConfig('embedding_weight') ?? 0.4);
        $llmEnabled = (bool) ($strategy->getConfig('llm_enabled') ?? false);
        $budget = (int) ($strategy->getConfig('llm_budget') ?? 200);

        // 1. Generate candidates and metadata scores using existing primitives
        // 2. Compute embedding cosine for each candidate pair
        // 3. Recompute composite score; mark merge/review
        // 4. Optional LLM tie-break when score in [75,85] and budget
        // 5. Persist with confidence (metadata) + ml_score/ml_* fields
        // 6. Telemetry: model, version, counts, cost, latency
    }
}
```

**Dependencies:** embeddings sidecar + `docs/ml-augmentation-design.md` §3.3/§4; do not implement inside this research deliverable.

---

### 4.4 PHP scaffolds: registration, config keys, telemetry, tests

### 4.4.1 Registration (`StrategyConfigurationFactory`)

```php
public const AVAILABLE_STRATEGIES = [
    RelationalMappingStrategy::class,
    RelationalFuzzyStrategy::class,
    MetadataFuzzyStrategy::class,
    \Hiyori\Service\Combiner\Strategy\GraphComponentsStrategy::class,      // planned, optional
    \Hiyori\Service\Combiner\Strategy\FellegiSunterStrategy::class,      // planned, experimental
    \Hiyori\Service\Combiner\Strategy\SemanticRerankStrategy::class,     // design-only initially
];
```

Keep default in `CombinerCommand` as `MetadataFuzzyStrategy::class`. Add CLI help text only, no behavior change default.

### 4.4.2 Strategy config keys (proposed)

| Strategy | Key | Type | Default |
|---|---|---|---|
| metadata_fuzzy | `weights` | object | `DEFAULT_WEIGHTS` |
| metadata_fuzzy | `threshold` | float | `70.0` |
| metadata_fuzzy | `anchor_rejection` | bool | `false` |
| metadata_fuzzy | `anchor_year_tolerance` | int | `1` |
| graph_components | `edge_title_threshold` | float | `0.82` |
| graph_components | `component_confidence_floor` | float | `70.0` |
| graph_components | `max_component_size` | int | `8` |
| fellegi_sunter | `m`, `u` | object | calibrated defaults |
| fellegi_sunter | `match_threshold` | float | `12.0` |
| fellegi_sunter | `nonmatch_threshold` | float | `3.0` |
| semantic_rerank | `review_floor` | float | `70.0` |
| semantic_rerank | `candidate_k` | int | `5` |
| semantic_rerank | `embedding_weight` | float | `0.4` |
| semantic_rerank | `llm_enabled` | bool | `false` |
| semantic_rerank | `llm_budget` | int | `200` |
| all strategies | `collection` | string | `organized` |

### 4.4.3 Telemetry benchmarks

Proposed benchmark keys for `storage/state/`:

- `combine-latest.json` (existing, extended fields).
- `combine-events.jsonl` (append per merge).
- `combine-benchmark.jsonl` (threshold sweep results; P/R/F1).
- `combine-components.jsonl` (graph component review records).

Dashboard compatibility: new fields are additive; no existing field renames.

### 4.4.4 Tests

Unit (PHP, following `tests/Unit/MetadataFuzzyStrategyTest.php` style):

- `GraphComponentsStrategyTest`: union-find, edge predicate, component confidence, max-size skip.
- `FellegiSunterStrategyTest`: score monotonicity, classification band, calibration loading.
- `HelperTitleNormalizationTest`: unicode normalization, token set, anchor mismatch.
- `SemanticRerankStrategyTest`: score formula, budget gating, missing embeddings graceful fallback.

Integration (opt-in, Mongo):

- `CombinerStrategyRegistrationTest`: each new strategy selectable by NAME and runs without throwing on empty collection.
- Threshold sweep harness in `tests/Integration` or `scripts/benchmark-combiner.php`: produce JSON at `65/70/75/80`.

Quality gate (release pipeline): no migration required for config-only improvements; migration v4 only for ML fields if semantic re-rank implemented per `ml-augmentation-design.md`.

### 4.4.5 Helper additions summary

- `Helper::normalizeTitleUnicode()` / `titleTokenSet()` — better title matching.
- `Helper::fellegiSunterScore()` (or move to strategy) — probabilistic linkage.
- `Helper::unionFind()` / edge helpers — graph components.
- `Helper::anchorFieldsAlign()` — shared veto for metadata/graph/FS.
- `Helper::median()` etc. optional for telemetry.

Keep helpers static and pure to preserve testability shown in existing unit tests.

---

## 5. Recommendations and rollout ordering (low risk, reversible)

Order, each independently reversible by strategy selection/config flag:

1. **R1 (docs only):** merge this research doc and dashboard Batch 4 items. No code.
2. **R2 (telemetry):** add `rejected_anchor`, `candidates_examined`, `per_source` fields and `combine-events.jsonl`. Backward-compatible.
3. **R3 (config flags in metadata_fuzzy):** add `anchor_rejection=false`, `anchor_year_tolerance=1`, `normalize_title_unicode=false`. Defaults preserve current behavior.
4. **R4 (tests first):** add unit tests for anchor rejection and normalization parity against existing `MetadataFuzzyStrategyTest`.
5. **R5 (enable anchor rejection in staging):** run threshold sweep `65/70/75/80`; only enable by default if precision ≥ baseline and F1 ≥ baseline + agreed margin.
6. **R6 (weight recalibration):** run `weightage` on 600+ pairs (anime + manga), store artifacts; accept only if precision does not drop and recall drop ≤ 1%.
7. **R7 (graph components optional):** register and implement behind `strategy=graph_components`. Validate component sizes and confidence floors on a snapshot before any default change.
8. **R8 (Fellegi–Sunter experimental):** implement `m/u` calibration; expose review band; never auto-merge below `match_threshold` without labels.
9. **R9 (semantic re-rank):** follow `ml-augmentation-design.md`; start with embedding cosine only, LLM tie-break gated by budget; store `ml_*` fields.
10. **R10 (default policy):** keep `metadata_fuzzy` default until offline A/B shows F1 ≥ baseline + 2 points and duplicate keys = 0; then optionally promote graph/FS/semantic via release gate.

Rollback: select previous strategy/config flags; no destructive migration required for R1–R8. Semantic re-rank adds optional fields, safe to leave unused.

---

## References

- `src/Service/Combiner/Combiner.php` — dispatch.
- `src/Service/Combiner/Strategy/CombinerStrategy.php`, `CombinerStrategyInterface.php` — base/registration contract.
- `src/Service/Combiner/Strategy/MetadataFuzzyStrategy.php` — baseline default strategy.
- `src/Service/Combiner/Strategy/RelationalMappingStrategy.php` — exact ID joins.
- `src/Service/Combiner/Strategy/RelationalFuzzyStrategy.php` — ID-overlap joins.
- `src/Service/StrategyConfigurationFactory.php` — `AVAILABLE_STRATEGIES`.
- `src/Commands/CombinerCommand.php` — CLI surface (`-s`, `-t`, `-w`, `--into`).
- `src/Commands/WeightageCommand.php` — discrimination-gap weight optimization.
- `src/Helper.php` — `resolveReferenceId`, `mergeEntries`, conversion.
- `src/Service/Exporter.php` — `referenceIds`, `slugify`.
- `src/Service/Telemetry.php` — state/histogram/event persistence.
- `docs/ml-augmentation-design.md` — ML storage convention and embedding shortlist.
- `docs/release-pipeline-design.md` — release-side quality/diff gates.
- `tests/Unit/MetadataFuzzyStrategyTest.php` — existing scoring unit-test baseline.
- `tests/Integration/CombinerTest.php` — organized upsert/idempotency baseline.
