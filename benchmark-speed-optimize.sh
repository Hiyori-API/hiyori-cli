#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
export PATH="$HOME/.opencode/bin:$PATH"
M=mongosh
BASE_ENTRIES=19337

echo "=== FULL BENCHMARK + INGESTION COMPLETION: $(date) ===" > storage/logs/full-bench.log

bench_run() {
  local name="$1" strategy="$2"
  local start_ms end_ms wall_s merges
  local time_out peak

  "$M" --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized.drop();'

  start_ms=$(date +%s%N)
  time_out=$(/usr/bin/time -v php hiyori combine anilist --strategy "$strategy" 2>&1)
  end_ms=$(date +%s%N)
  wall_s=$(echo "scale=3; ($end_ms - $start_ms)/1000000000" | bc)

  peak=$(echo "$time_out" | grep "Maximum resident set size" | grep -oE '[0-9]+')
  merges=$(echo "$time_out" | grep -oE 'Total Merged: [0-9]+' | grep -oE '[0-9]+')
  total=$("$M" --quiet --eval 'db=db.getSiblingDB("hiyori"); print(db.organized.countDocuments())')

  echo "RESULT strategy=$name wall=${wall_s}s entries/s=$(echo "scale=1; $BASE_ENTRIES/$wall_s" | bc) merges=$merges merges/s=$(echo "scale=1; ${merges:-0}/$wall_s" | bc) organized=$total peak_rss_kb=${peak:-n/a}" >> storage/logs/full-bench.log
}

echo "### SPEED BENCHMARK (base=anilist, $BASE_ENTRIES entries) ###" >> storage/logs/full-bench.log
bench_run relational_mapping relational_mapping
bench_run relational_fuzzy relational_fuzzy
bench_run metadata_fuzzy metadata_fuzzy

echo "### RESTORE CLEAN organized ###" >> storage/logs/full-bench.log
"$M" --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized.drop();'
mongorestore --uri="mongodb://localhost:27017" --db=hiyori --gzip --archive=storage/backups/hiyori-20261012-091434.gz --nsInclude=hiyori.organized --drop 2>&1 | tail -1 >> storage/logs/full-bench.log

echo "### COMPLETE INGESTION (top-up, idempotent) ###" >> storage/logs/full-bench.log
for src in anilist kitsu myanimelist; do
  echo ">>> ingest $src start $(date)" >> storage/logs/full-bench.log
  php hiyori ingest "$src" --delay 1 >> storage/logs/full-bench.log 2>&1
  echo ">>> ingest $src done $(date)" >> storage/logs/full-bench.log
done

echo "### FINAL COUNTS ###" >> storage/logs/full-bench.log
"$M" --quiet --eval 'db=db.getSiblingDB("hiyori"); ["myanimelist","kitsu","anilist"].forEach(c=>print(c+": "+db.getCollection(c).countDocuments())); print("organized: "+db.organized.countDocuments())' >> storage/logs/full-bench.log
echo "=== END: $(date) ===" >> storage/logs/full-bench.log
