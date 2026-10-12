#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
export PATH="$HOME/.opencode/bin:$PATH"
M=mongosh
LOG=storage/logs/ingest-parallel.log
: > "$LOG"
echo "=== PARALLEL INGESTION START $(date) ===" >> "$LOG"

declare -A PIDS
for src in anilist kitsu myanimelist; do
  ( php hiyori ingest "$src" --delay 1 >> "$LOG" 2>&1; echo "DONE $src exit=$? $(date)" >> "$LOG" ) &
  PIDS[$src]=$!
  echo "launched $src pid=${PIDS[$src]}" >> "$LOG"
done

# Wait for each (report as they finish)
for src in anilist kitsu myanimelist; do
  wait "${PIDS[$src]}"
done

echo "### ALL SOURCES FINISHED $(date) ###" >> "$LOG"
"$M" --quiet --eval 'db=db.getSiblingDB("hiyori"); ["myanimelist","kitsu","anilist"].forEach(c=>print(c+": "+db.getCollection(c).countDocuments())); print("organized (unchanged): "+db.organized.countDocuments())' >> "$LOG"
echo "=== END $(date) ===" >> "$LOG"
