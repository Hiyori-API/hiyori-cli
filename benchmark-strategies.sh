#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
export PATH="$HOME/.opencode/bin:$PATH"
MONGOSH='mongosh'

measure() {
  local strategy="$1"
  "$MONGOSH" --quiet --eval "db=db.getSiblingDB(\"hiyori\");
    let total=db.organized.countDocuments();
    let al=db.organized.countDocuments({\"reference_ids.al\":{\$exists:true}});
    let mal=db.organized.countDocuments({\"reference_ids.mal\":{\$exists:true}});
    let k=db.organized.countDocuments({\"reference_ids.k\":{\$exists:true}});
    let high=db.organized.countDocuments({\$and:[{\"reference_ids.al\":{\$exists:true}},{\"reference_ids.mal\":{\$exists:true}},{\"reference_ids.k\":{\$exists:true}}]});
    let only=db.organized.countDocuments({\$and:[{\"reference_ids.al\":{\$exists:true}},{\"reference_ids.mal\":{\$exists:false}},{\"reference_ids.k\":{\$exists:false}}]});
    let dup=db.organized.aggregate([{\$group:{_id:\"\$reference_ids.al\",n:{\$sum:1}}},{\$match:{_id:{\$ne:null},n:{\$gt:1}}},{\$count:\"c\"}]).toArray();
    let avg=db.organized.aggregate([{\$project:{n:{\$size:{\$objectToArray:\"\$reference_ids\"}}}},{\$group:{_id:null,avg:{\$avg:\"\$n\"}}}]).toArray();
    print(JSON.stringify({strategy:\"$strategy\",total:total,has_al:al,has_mal:mal,has_k:k,high_conf_3sources:high,only_base_1source:only,duplicate_keys:dup[0]?.c??0,avg_reference_sources:avg[0]?.avg??0}));"
}

echo "=== Strategy Benchmark Start: $(date) ===" > storage/logs/strategy-benchmark.log

echo ">>> relational_fuzzy (start $(date))" >> storage/logs/strategy-benchmark.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized.drop();'
php hiyori combine anilist --strategy relational_fuzzy 2>&1 | tail -1 >> storage/logs/strategy-benchmark.log
measure "relational_fuzzy" >> storage/logs/strategy-benchmark.log

echo ">>> metadata_fuzzy (start $(date))" >> storage/logs/strategy-benchmark.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized.drop();'
php hiyori combine anilist --strategy metadata_fuzzy 2>&1 | tail -1 >> storage/logs/strategy-benchmark.log
measure "metadata_fuzzy" >> storage/logs/strategy-benchmark.log

echo ">>> restoring clean snapshot" >> storage/logs/strategy-benchmark.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori"); db.organized.drop();'
mongorestore --uri="mongodb://localhost:27017" --db=hiyori --gzip --archive=storage/backups/hiyori-20261012-091434.gz --nsInclude=hiyori.organized --drop 2>&1 | tail -2 >> storage/logs/strategy-benchmark.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori"); print("restored organized count: "+db.organized.countDocuments())' >> storage/logs/strategy-benchmark.log
echo "=== Benchmark End: $(date) ===" >> storage/logs/strategy-benchmark.log
