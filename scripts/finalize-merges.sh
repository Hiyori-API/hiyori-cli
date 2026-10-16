#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
LOG=storage/logs/finalize-merges.log
echo "=== finalize merges start $(date) ===" >> $LOG

wait_lock() {
  echo "  waiting for merge lock to free… $(date)" >> $LOG
  while pgrep -f "php hiyori combine" >/dev/null 2>&1; do sleep 60; done
  echo "  lock free $(date)" >> $LOG
}

wait_lock
echo ">> anime combine start $(date)" >> $LOG
# --passthrough: keep base entries that matched nothing as single-source records,
# so one document per ingested entry survives and no registry id loses its document.
php hiyori combine anilist --strategy metadata_fuzzy --passthrough >> $LOG 2>&1
echo ">> anime combine end $(date)" >> $LOG
mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); print("organized: "+db.organized.countDocuments())' >> $LOG 2>&1

wait_lock
echo ">> manga combine start $(date)" >> $LOG
php hiyori combine anilist_manga --strategy metadata_fuzzy --passthrough --into organized_manga >> $LOG 2>&1
echo ">> manga combine end $(date)" >> $LOG
mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); print("organized_manga: "+db.organized_manga.countDocuments())' >> $LOG 2>&1

echo "=== finalize merges end $(date) ===" >> $LOG
