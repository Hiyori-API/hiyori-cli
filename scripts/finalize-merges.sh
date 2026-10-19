#!/usr/bin/env bash
# Rebuild the merged collections through the release orchestrator.
#
# The orchestrator (`php hiyori pipeline:release`) owns the stage order, checks
# a precondition before every stage and refuses to continue on a bad input.
# This script only decides *which* stages run: the two combines and nothing
# else -- no migration, no id backfill, no release. The combines are the
# orchestrator's `combine:anime` and `combine:manga` stages, which run the same
# commands this script used to run by hand (base anilist / anilist_manga,
# --strategy metadata_fuzzy, --passthrough, manga into organized_manga).
#
# A failed combine used to be logged as a normal end of the run; the
# orchestrator's exit code is now propagated, so a crashed rebuild is a failed
# rebuild.
#
# Usage: scripts/finalize-merges.sh
set -euo pipefail
cd "$(dirname "$0")/.."

LOG=storage/logs/finalize-merges.log
mkdir -p "$(dirname "$LOG")"
echo "=== finalize merges start $(date) ===" >> "$LOG"

wait_lock() {
  echo "  waiting for merge lock to free… $(date)" >> "$LOG"
  while pgrep -f "php hiyori combine" >/dev/null 2>&1; do sleep 60; done
  echo "  lock free $(date)" >> "$LOG"
}

wait_lock

# --from=combine:anime starts at the first combine; the stages after the
# combines are skipped on purpose, so this script keeps its scope.
status=0
php hiyori pipeline:release \
  --from=combine:anime \
  --skip=hiyori-id \
  --skip=dupes \
  --skip=dedupe \
  --skip=release \
  >> "$LOG" 2>&1 || status=$?

if [ "$status" -ne 0 ]; then
  echo "=== finalize merges FAILED (exit $status) $(date) ===" >> "$LOG"
  exit "$status"
fi

# The counts are a log line, not a gate: a missing mongosh must not turn a
# successful rebuild into a failed one.
mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); print("organized: "+db.organized.countDocuments())' >> "$LOG" 2>&1 || true
mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); print("organized_manga: "+db.organized_manga.countDocuments())' >> "$LOG" 2>&1 || true

echo "=== finalize merges end $(date) ===" >> "$LOG"
