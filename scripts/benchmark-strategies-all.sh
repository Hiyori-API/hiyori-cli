#!/usr/bin/env bash
#
# Benchmark every combiner strategy on one base source, in isolation.
#
# Safety contract
#   * Never touches the ingested source tables.
#   * Never touches `organized` / `organized_manga` — each strategy writes to
#     its own `bench_<strategy>` scratch collection via `--into`.
#   * Snapshots `_hiyori_ids` + `_counters` first and restores them before every
#     run, so each strategy starts from an identical, clean id registry and the
#     live registry is left exactly as found.
#
# Usage
#   scripts/benchmark-strategies-all.sh [BASE] [STRATEGY ...]
#   BENCH_DRY_RUN=1 scripts/benchmark-strategies-all.sh   # mechanics only
#
set -u
cd "$(dirname "$0")/.."

MONGODB_URL="${MONGODB_URL:-mongodb://localhost:27017}"
BASE="${1:-anilist}"
shift || true
if [ "$#" -gt 0 ]; then
  STRATEGIES=("$@")
else
  STRATEGIES=(relational_mapping relational_fuzzy metadata_fuzzy graph_merge fellegi_sunter semantic_rerank metadata_ml)
fi

TS="$(date +%Y%m%d-%H%M%S)"
RESULTS="storage/state/strategy-benchmark-${TS}.jsonl"
LOG="storage/logs/strategy-bench-${TS}.log"
SNAP_IDS="_bench_snapshot_ids"
SNAP_COUNTERS="_bench_snapshot_counters"
PER_RUN_TIMEOUT="${BENCH_TIMEOUT:-7200}"
DRY_RUN="${BENCH_DRY_RUN:-0}"

mkdir -p storage/state storage/logs
: > "$RESULTS"

log() { echo "$@" | tee -a "$LOG"; }

log "=== strategy benchmark start $(date) ==="
log "base=${BASE} strategies=${STRATEGIES[*]} dry_run=${DRY_RUN}"

BASE_MAX="$(mongosh --quiet "$MONGODB_URL/hiyori" --eval 'print(db.getCollection("_hiyori_ids").find().sort({hiyori_id:-1}).limit(1).toArray().map(r=>r.hiyori_id)[0] ?? 0)')"
log "baseline max hiyori_id = ${BASE_MAX}"

# --- snapshot shared bookkeeping (NOT sources, NOT organized) --------------
# mongosh copy rather than mongodump: this host's mongodump lacks --nsInclude,
# and a document-level copy preserves the live collection's indexes.
mongosh --quiet "$MONGODB_URL/hiyori" --eval "
  db.getCollection('${SNAP_IDS}').drop();
  db.getCollection('_hiyori_ids').aggregate([{\$match:{}},{\$out:'${SNAP_IDS}'}]).toArray();
  db.getCollection('${SNAP_COUNTERS}').drop();
  db.getCollection('_counters').aggregate([{\$match:{}},{\$out:'${SNAP_COUNTERS}'}]).toArray();
" >/dev/null 2>&1
_log_snap="$(mongosh --quiet "$MONGODB_URL/hiyori" --eval "print(db.getCollection('${SNAP_IDS}').countDocuments())")"
[ "${_log_snap:-0}" -gt 0 ] || { log "FATAL: registry snapshot failed"; exit 1; }
log "registry snapshot -> ${SNAP_IDS} (${_log_snap} rows)"

restore_registry() {
  mongosh --quiet "$MONGODB_URL/hiyori" --eval "
    var ids = db.getCollection('${SNAP_IDS}').find().toArray();
    db.getCollection('_hiyori_ids').deleteMany({});
    if (ids.length) db.getCollection('_hiyori_ids').insertMany(ids, {ordered:false});
    var ctr = db.getCollection('${SNAP_COUNTERS}').find().toArray();
    db.getCollection('_counters').deleteMany({});
    if (ctr.length) db.getCollection('_counters').insertMany(ctr, {ordered:false});
  " >/dev/null 2>&1
}

# --- measurement query -----------------------------------------------------
cat > /tmp/bench-measure.js <<'JS'
const S = process.env.BENCH_STRAT;
const BASE_MAX = parseInt(process.env.BENCH_BASE_MAX || "0", 10);
const C = "bench_" + S;
const col = db.getCollection(C);
const reg = db.getCollection("_hiyori_ids");
const one = (agg) => (agg[0] && agg[0].c) || 0;
const dupOf = (field) => one(col.aggregate([
  { $match: { [field]: { $exists: true } } },
  { $group: { _id: "$" + field, n: { $sum: 1 } } },
  { $match: { n: { $gt: 1 } } }, { $count: "c" },
]).toArray());
const total = col.countDocuments();
const hasAl = col.countDocuments({ "reference_ids.al": { $exists: true } });
const hasMal = col.countDocuments({ "reference_ids.mal": { $exists: true } });
const hasK = col.countDocuments({ "reference_ids.k": { $exists: true } });
const triple = col.countDocuments({ $and: [
  { "reference_ids.al": { $exists: true } },
  { "reference_ids.mal": { $exists: true } },
  { "reference_ids.k": { $exists: true } },
] });
const onlyBase = col.countDocuments({ $and: [
  { "reference_ids.al": { $exists: true } },
  { "reference_ids.mal": { $exists: false } },
  { "reference_ids.k": { $exists: false } },
] });
const avgRow = col.aggregate([
  { $project: { n: { $size: { $objectToArray: { $ifNull: ["$reference_ids", {}] } } } } },
  { $group: { _id: null, avg: { $avg: "$n" } } },
]).toArray();
const orphans = one(reg.aggregate([
  { $match: { kind: "anime" } },
  { $lookup: { from: C, localField: "hiyori_id", foreignField: "hiyori_id", as: "d" } },
  { $match: { d: { $size: 0 } } }, { $count: "c" },
]).toArray());
const newIds = reg.countDocuments({ hiyori_id: { $gt: BASE_MAX } });
print(JSON.stringify({
  strategy: S,
  collection: C,
  total: total,
  has_al: hasAl,
  has_mal: hasMal,
  has_k: hasK,
  triple_linked: triple,
  only_base_1source: onlyBase,
  avg_sources: Number((avgRow[0] ? avgRow[0].avg : 0).toFixed(2)),
  dup_al: dupOf("reference_ids.al"),
  dup_mal: dupOf("reference_ids.mal"),
  dup_hiyori_id: one(col.aggregate([
    { $group: { _id: "$hiyori_id", n: { $sum: 1 } } },
    { $match: { n: { $gt: 1 } } }, { $count: "c" },
  ]).toArray()),
  dup_slug: dupOf("slug"),
  new_hiyori_ids: newIds,
  orphan_registry_ids: orphans,
}));
JS

for S in "${STRATEGIES[@]}"; do
  COLL="bench_${S}"
  runlog="storage/logs/strategy-bench-${TS}-${S}.log"
  log ""
  log ">>> ${S}  ($(date))"

  if [ "$DRY_RUN" = "1" ]; then
    log "    [dry-run] skipping combine; measuring ${COLL} as-is"
    wall="n/a"; rss="0"; exit_code=0
  else
    restore_registry
    mongosh --quiet "$MONGODB_URL/hiyori" --eval "db.getCollection('${COLL}').drop()" >/dev/null 2>&1
    t0="$(date +%s%N)"
    timeout "$PER_RUN_TIMEOUT" /usr/bin/time -v -o /tmp/bench-time.txt \
      php hiyori combine "$BASE" --strategy "$S" --into "$COLL" --export=none > "$runlog" 2>&1
    exit_code=$?
    t1="$(date +%s%N)"
    wall="$(awk -v a="$t0" -v b="$t1" 'BEGIN{printf "%.1f", (b-a)/1000000000}')"
    rss="$(grep -a 'Maximum resident set size' /tmp/bench-time.txt | grep -oE '[0-9]+' | head -1)"
    rss="${rss:-0}"
    if [ "$exit_code" != "0" ]; then
      log "    !! combine exit=${exit_code} (see ${runlog})"
    fi
  fi

  measure="$(BENCH_STRAT="$S" BENCH_BASE_MAX="$BASE_MAX" mongosh --quiet "$MONGODB_URL/hiyori" --file /tmp/bench-measure.js 2>/dev/null | tail -1)"
  [ -n "$measure" ] || measure='{"strategy":"'"$S"'","error":"measure failed"}'

  # strategy telemetry snapshot (each strategy overwrites combine-latest)
  tel="storage/state/bench-telemetry-${S}.json"
  [ -f storage/state/combine-latest.json ] && cp storage/state/combine-latest.json "$tel"

  row="$(S="$S" W="$wall" R="$rss" EC="$exit_code" M="$measure" T="$tel" python3 -c '
import json, os
m = json.loads(os.environ["M"])
m["wall_seconds"] = None if os.environ["W"] == "n/a" else float(os.environ["W"])
r = os.environ.get("R", "0")
m["peak_rss_kb"] = int(r) if r.isdigit() else 0
m["exit_code"] = int(os.environ["EC"])
m["telemetry_file"] = os.environ["T"]
if m.get("wall_seconds"):
    entries = 15015
    m["entries_per_second"] = round(entries / m["wall_seconds"], 2)
print(json.dumps(m))
')"
  echo "$row" >> "$RESULTS"
  log "    ${row}"
done

restore_registry
mongosh --quiet "$MONGODB_URL/hiyori" --eval "db.getCollection('${SNAP_IDS}').drop(); db.getCollection('${SNAP_COUNTERS}').drop()" >/dev/null 2>&1
log ""
log "registry restored; sources and organized untouched."
log "results: ${RESULTS}"
log "=== done $(date) ==="
