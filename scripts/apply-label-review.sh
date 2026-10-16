#!/usr/bin/env bash
#
# Apply a pair-review sheet to the label store.
#
# A sheet is a TSV of judgements (`docs/labels/*.tsv`): one row per pair, with
# `left`, `right` as `<source shorthand>:<source id>`, a label (1 = same work,
# 0 = different work) and a free-text note.
#
# Every row is written in BOTH directions, because a label is looked up as
# `forBase(base_source, base_id)` by `ml:export-pairs` and `combine:consensus`:
# a pair recorded only as kitsu -> anilist would be invisible to an export whose
# base is anilist.
#
# Usage:
#   bash scripts/apply-label-review.sh docs/labels/anime-merge-review-2026-10-16.tsv [source]
#
# `source` defaults to `agent-review` so automated review passes stay
# distinguishable from labels a person entered in the dashboard (`human`).
set -euo pipefail
cd "$(dirname "$0")/.."

SHEET="${1:-}"
if [ -z "$SHEET" ] || [ ! -f "$SHEET" ]; then
    echo "usage: $0 <sheet.tsv> [source]" >&2
    exit 2
fi
SOURCE="${2:-agent-review}"

rows=0
written=0
while IFS=$'\t' read -r left right label note; do
    case "${left:-}" in
        ''|'#'*) continue ;;
    esac
    if [ -z "${right:-}" ] || [ -z "${label:-}" ]; then
        echo "skipping malformed row: $left $right $label" >&2
        continue
    fi
    ls="${left%%:*}"
    li="${left#*:}"
    rs="${right%%:*}"
    ri="${right#*:}"
    php hiyori labels add \
        --base-source "$ls" --base-id "$li" \
        --candidate-source "$rs" --candidate-id "$ri" \
        --label "$label" --source "$SOURCE" --notes "${note:-}" >/dev/null
    php hiyori labels add \
        --base-source "$rs" --base-id "$ri" \
        --candidate-source "$ls" --candidate-id "$li" \
        --label "$label" --source "$SOURCE" --notes "${note:-}" >/dev/null
    rows=$((rows + 1))
    written=$((written + 2))
done < "$SHEET"

echo "applied ${rows} judgements (${written} labels, source: ${SOURCE}) from ${SHEET}"
