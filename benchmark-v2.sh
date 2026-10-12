#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
WEIGHTS='{"title":26.4,"episodes":26.2,"year":23.2,"season":16.4,"type":5.7,"tags":1.5,"status":0.6}'
LOG=storage/logs/strategy-benchmark-v2.log
echo "=== Benchmark v2 start $(date) ===" > $LOG

measure() {
  local strategy="$1"
  mongosh --quiet --eval "db=db.getSiblingDB(\"hiyori\");
    let total=db.organized.countDocuments();
    let al=db.organized.countDocuments({\"reference_ids.al\":{\$exists:true}});
    let mal=db.organized.countDocuments({\"reference_ids.mal\":{\$exists:true}});
    let k=db.organized.countDocuments({\"reference_ids.k\":{\$exists:true}});
    let high=db.organized.countDocuments({\$and:[{\"reference_ids.al\":{\$exists:true}},{\"reference_ids.mal\":{\$exists:true}},{\"reference_ids.k\":{\$exists:true}}]});
    let only=db.organized.countDocuments({\$and:[{\"reference_ids.al\":{\$exists:true}},{\"reference_ids.mal\":{\$exists:false}},{\"reference_ids.k\":{\$exists:false}}]});
    let dup=db.organized.aggregate([{\$group:{_id:\"\$reference_ids.al\",n:{\$sum:1}}},{\$match:{_id:{\$ne:null},n:{\$gt:1}}},{\$count:\"c\"}]).toArray();
    let avg=db.organized.aggregate([{\$project:{n:{\$size:{\$objectToArray:\"\$reference_ids\"}}}},{\$group:{_id:null,avg:{\$avg:\"\$n\"}}}]).toArray();
    print(JSON.stringify({strategy:\"$strategy\",total:total,has_al:al,has_mal:mal,has_k:k,high_conf_3sources:high,only_base_1source:only,duplicate_keys:dup[0]?.c??0,avg_reference_sources:(avg[0]?.avg??0).toFixed(2)}));" >> $LOG
}

run() {
  local name="$1"; shift
  echo ">>> $name start $(date)" >> $LOG
  mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized.drop();' >> /dev/null
  local t0=$(date +%s)
  timeout 5400 php hiyori combine anilist "$@" >> $LOG 2>&1
  local t1=$(date +%s)
  echo "wall: $((t1 - t0))s" >> $LOG
  measure "$name" >> $LOG
}

run relational_mapping --strategy relational_mapping
run relational_fuzzy --strategy relational_fuzzy
run metadata_fuzzy  --strategy metadata_fuzzy --weights "$WEIGHTS"

echo "=== Benchmark v2 end $(date) ===" >> $LOG
mongosh --quiet --eval 'db=db.getSiblingDB("hiyori"); print("final organized count: "+db.organized.countDocuments())' >> $LOG
