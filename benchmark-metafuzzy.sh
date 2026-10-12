#!/usr/bin/env bash
set -u
cd /home/nekomata/projects/hiyori-cli
export PATH="$HOME/.opencode/bin:$PATH"
MONGOSH='mongosh'

measure() {
  local strategy="$1"
  "$MONGOSH" --quiet --eval "db=db.getSiblingDB(\"hiyori\");
    let total=db.organized.countDocuments();
    let mal=db.organized.countDocuments({\"reference_ids.mal\":{\$exists:true}});
    let k=db.organized.countDocuments({\"reference_ids.k\":{\$exists:true}});
    let high=db.organized.countDocuments({\$and:[{\"reference_ids.al\":{\$exists:true}},{\"reference_ids.mal\":{\$exists:true}},{\"reference_ids.k\":{\$exists:true}}]});
    let only=db.organized.countDocuments({\$and:[{\"reference_ids.al\":{\$exists:true}},{\"reference_ids.mal\":{\$exists:false}},{\"reference_ids.k\":{\$exists:false}}]});
    let dup=db.organized.aggregate([{\$group:{_id:\"\$reference_ids.al\",n:{\$sum:1}}},{\$match:{_id:{\$ne:null},n:{\$gt:1}}},{\$count:\"c\"}]).toArray();
    print(JSON.stringify({strategy:\"$strategy\",total:total,has_mal:mal,has_k:k,high_conf_3sources:high,only_base_1source:only,duplicate_keys:dup[0]?.c??0}));"
}

echo "=== metadata_fuzzy benchmark start $(date) ===" > storage/logs/metafuzzy-bench.log

echo ">>> run 1: defaults (threshold 70)" >> storage/logs/metafuzzy-bench.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori");db.organized.drop();'
php hiyori combine anilist --strategy metadata_fuzzy 2>&1 | grep -E "Merge complete|Confidence|Merges" >> storage/logs/metafuzzy-bench.log
measure "metadata_fuzzy_defaults" >> storage/logs/metafuzzy-bench.log

echo ">>> run 2: tuned (threshold 60, title/tags weighted)" >> storage/logs/metafuzzy-bench.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori");db.organized.drop();'
php hiyori combine anilist --strategy metadata_fuzzy --threshold 60 --weights '{"title":30,"tags":25,"year":15,"type":15,"status":15}' 2>&1 | grep -E "Merge complete|Confidence|Merges" >> storage/logs/metafuzzy-bench.log
measure "metadata_fuzzy_tuned" >> storage/logs/metafuzzy-bench.log

echo ">>> restoring clean snapshot" >> storage/logs/metafuzzy-bench.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori");db.organized.drop();'
mongorestore --uri="mongodb://localhost:27017" --db=hiyori --gzip --archive=storage/backups/hiyori-20261012-091434.gz --nsInclude=hiyori.organized --drop 2>&1 | tail -1 >> storage/logs/metafuzzy-bench.log
"$MONGOSH" --quiet --eval 'db=db.getSiblingDB("hiyori"); print("restored: "+db.organized.countDocuments())' >> storage/logs/metafuzzy-bench.log
echo "=== end $(date) ===" >> storage/logs/metafuzzy-bench.log
