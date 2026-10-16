#!/usr/bin/env bash
#
# Resume ingestion for every source, anime and manga, in parallel batches.
#
# The ingesters skip entries that already exist (`--update` is what opts into
# rewriting them), so re-running a source *is* the resume path: it re-walks its
# list and only fetches what is missing. Verified gaps when this was written
# (source collection vs the API's own count): kitsu 8,667 / 22,503 and
# kitsu_manga 7,560 / 63,224 — the others could not be checked from outside
# (AniList caps `pageInfo.total` at 5,000; the MAL ranking endpoint is itself
# capped), so they are re-walked rather than assumed complete.
#
# Batches bound the number of concurrent API clients (AniList is the strictest
# at ~90 req/min) and keep each source's progress readable in the log. The
# ingesters lock per source (`ingest-<source>`), so different sources may run
# side by side but a duplicate of the same source exits immediately.
#
# Usage:  bash scripts/resume-ingest.sh
# Env:    SOURCES="kitsu kitsu_manga"  DELAY=2  BATCH=2  LOG=storage/logs/x.log
set -u

cd "$(dirname "$0")/.."

DELAY="${DELAY:-1}"
BATCH="${BATCH:-3}"
LOG="${LOG:-storage/logs/ingest-resume.log}"
# Biggest verified gaps first, so the first batch is the one that matters.
SOURCES="${SOURCES:-kitsu kitsu_manga anilist myanimelist myanimelist_manga anilist_manga}"

mkdir -p "$(dirname "$LOG")"
{
  echo "=== RESUME INGEST $(date -Is) ==="
  echo "sources: $SOURCES | delay: ${DELAY}s | batch: $BATCH"
} >> "$LOG"

queue=($SOURCES)
while [ "${#queue[@]}" -gt 0 ]; do
  batch=("${queue[@]:0:$BATCH}")
  queue=("${queue[@]:$BATCH}")
  pids=()
  for src in "${batch[@]}"; do
    (
      echo "--- START $src $(date -Is)" >> "$LOG"
      php hiyori ingest "$src" --delay "$DELAY" >> "$LOG" 2>&1
      rc=$?
      echo "--- END $src exit=$rc $(date -Is)" >> "$LOG"
    ) &
    pids+=($!)
  done
  for pid in "${pids[@]}"; do wait "$pid"; done
  echo "--- batch done: ${batch[*]} $(date -Is)" >> "$LOG"
done

echo "### ALL SOURCES FINISHED $(date -Is) ###" >> "$LOG"
