#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
WEIGHTS='{"title":26.4,"episodes":26.2,"year":23.2,"season":16.4,"type":5.7,"tags":1.5,"status":0.6}'
LOG=storage/logs/strategy-benchmark-manga.log
INTO=organized_manga
echo "=== Benchmark manga start $(date) ===" > $LOG

measure() {
  local strategy="$1"
  mongosh --quiet --eval "db=db.getSiblingDB(\"hiyori\");
    let total=db.$INTO.countDocuments();
    let al=db.$INTO.countDocuments({\"reference_ids.al_manga\":{\$exists:true}});
    let mal=db.$INTO.countDocuments({\"reference_ids.mal_manga\":{\$exists:true}});
    let k=db.$INTO.countDocuments({\"reference_ids.k_manga\":{\$exists:true}});
    let high=db.$INTO.countDocuments({\$and:[{\"reference_ids.al_manga\":{\$exists:true}},{\"reference_ids.mal_manga\":{\$exists:true}},{\"reference_ids.k_manga\":{\$exists:true}}]});
    let only=db.$INTO.countDocuments({\$and:[{\"reference_ids.al_manga\":{\$exists:true}},{\"reference_ids.mal_manga\":{\$exists:false}},{\"reference_ids.k_manga\":{\$exists:false}}]});
    let dup=db.$INTO.aggregate([{\$group:{_id:\"\$reference_ids.al_manga\",n:{\$sum:1}}},{\$match:{_id:{\$ne:null},n:{\$gt:1}}},{\$count:\"c\"}]).toArray();
    let avg=db.$INTO.aggregate([{\$project:{n:{\$size:{\$objectToArray:\"\$reference_ids\"}}}},{\$group:{_id:null,avg:{\$avg:\"\$n\"}}}]).toArray();
    print(JSON.stringify({strategy:\"$strategy\",total:total,has_al_manga:al,has_mal_manga:mal,has_k_manga:k,triple_linked:high,only_base:only,duplicate_keys:dup[0]?.c??0,avg_reference_sources:(avg[0]?.avg??0).toFixed(2)}));" >> $LOG
}

run() {
  local name="$1"; shift
  echo ">>> $name start $(date)" >> $LOG
  mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized_manga.drop();' >> /dev/null
  local t0=$(date +%s)
  timeout 5400 php hiyori combine anilist_manga "$@" >> $LOG 2>&1
  local t1=$(date +%s)
  echo "wall: $((t1 - t0))s" >> $LOG
  measure "$name" >> $LOG
}

run relational_mapping --strategy relational_mapping --into organized_manga
run relational_fuzzy --strategy relational_fuzzy --into organized_manga
run metadata_fuzzy --strategy metadata_fuzzy --weights "$WEIGHTS" --into organized_manga

echo "=== Benchmark manga end $(date) ===" >> $LOG
mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); print("final organized_manga count: "+db.organized_manga.countDocuments())' >> $LOG