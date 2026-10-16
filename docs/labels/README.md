# Pair-review sheets

Sheets here are the record of a *merge review pass*: a sample of merged
`organized` documents was drawn, each reference in the document was compared
against the other references in the same document, and the verdict was stored as
a pair label in `_labels`.

They are committed so the label set is auditable and can be re-applied to a
fresh database:

```bash
bash scripts/apply-label-review.sh docs/labels/anime-merge-review-2026-10-16.tsv
```

## Provenance (read this before quoting the numbers)

The labels in `anime-merge-review-2026-10-16.tsv` were produced by an
**automated review at the title/metadata level**, not by a person clicking
through the review UI, and they are stored with `source = agent-review` so they
stay separable from human labels in `php hiyori labels stats`.

This matters because `ml:export-pairs` and `combine:consensus` treat *any*
stored label as human ground truth (they do not filter on `source`). So the
`human F1` column in a consensus report will include these labels unless they
are excluded. They are honest verdicts, but they were reached by comparing
source titles and years — the same evidence the combiner scores on — so they can
flatter a title-similarity model more than a human label would.

## Sampling bias

Samples are drawn from documents that *are* merges (`/api/sample` on
`organized`), so the sheet is mostly positives plus whatever bad merges are in
the weak tail of the confidence distribution. It is not a random sample of all
candidate pairs: the negatives are concentrated in the 70–80 confidence band and
in the "identical type/episodes/season/year, unrelated titles" class.

## The 2026-10-16 anime pass

30 documents drawn at `min=0` (the full confidence range, 72.1–99.0), 57
judgements written (42 agree / 15 disagree), each stored in both directions.

What it found:

* The high-confidence merges (≥ 95) are almost always correct — sources agree on
  title, year, type and episode count.
* **15 of 30 sampled documents contained at least one wrong reference**, and the
  wrong references are overwhelmingly *AniList entries merged against an
  unrelated Kitsu entry* at a suspiciously constant confidence of **95.86**.
* 95.86 is explained exactly: the pair scores 72.10 on the current scorer and
  `72.10 + 0.9 × 26.4 = 95.86` — that is a **0.9 synonym-overlap bonus** applied
  to a pair whose titles have zero similarity
  (`titleSimilarity = 0.0`, and `isTitleRejected()` is `true` today).
  The pairs are identical on `type`/`episodes`/`status`/`season`/`year` and the
  old synonym comparison matched on empty-string synonyms — the exact failure
  that `nonEmptyNormalizedTitles()` was added to stop (see the comment in
  `MetadataFuzzyStrategy::titleSimilarity()`).

So these are **stale documents from the scorer before both fixes** (blank-synonym
drop *and* the title floor), not a live regression: `metadata_fuzzy` as shipped
rejects all 15 pairs. They are still useful as labels — a 0 for "these two
entries are different works" is true regardless of which code produced the
document — and they are a baseline the passthrough rebuild can be measured
against: the rebuild should not contain them at all.
