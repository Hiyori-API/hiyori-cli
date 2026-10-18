#!/bin/bash
# Sequential resume of the batch-2 ingests.
#
# The previous parallel run died with a MongoDB ConnectionTimeoutException
# during a bulk write: three concurrent ingesters writing at once exhausted the
# connection. These are run strictly one after another.
#
# No --update, so each source skips what is already stored and this is a resume,
# not a re-import.
cd /home/nekomata/projects/hiyori-cli
LOG=storage/logs/ingest-resume-2.log
: > "$LOG"

for src in myanimelist myanimelist_manga anilist_manga; do
  echo "--- START $src $(date -Is)" >> "$LOG"
  php hiyori ingest "$src" --delay 1 >> "$LOG" 2>&1
  echo "--- END $src exit=$? $(date -Is)" >> "$LOG"
done

echo "--- ALL DONE $(date -Is)" >> "$LOG"
