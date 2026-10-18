# Manga identity repair — 2026-10-19

The manga sources never resolved an identity. This records what was wrong, what
was changed, the backups that make it reversible, and what is still outstanding.

## What was wrong

The identifier table only knew `/anime/` URL shapes (`AniList::PATTERN =
"anilist\.co\/anime\/([\d]+)"`, and the Kitsu/MyAnimeList equivalents), while the
manga sources are configured with `al_manga` / `k_manga` / `mal_manga`. So every
manga document resolved no id.

Three consequences, all verified against the live database before the repair:

| Collection | Documents | `reference_ids` |
|---|---|---|
| `anilist_manga` | 65,373 | 57,962 empty; 7,411 holding a single **anime** `al` |
| `kitsu_manga` | 70,787 | all empty |
| `myanimelist_manga` | 59,826 | all empty |

1. **Ingest never resumed.** `exists($table, 'al_manga', $id)` was always false,
   so re-running a manga source re-inserted the whole catalogue instead of
   skipping what it already had.
2. **`combine` could not see the data.** Only the 2,161 rows that happened to
   match an anime id were merged, so `organized_manga` held 2,161 of 65,373.
3. **Identity was wrong, not merely missing.** The manga models fed related works
   into the identity list, and `getModel()` is last-wins. A related anime put its
   id under `al` on a manga document, and a related manga overwrote the entry's
   own id. Because the registry is keyed on those ids, the 2,161 manga entries
   were registered under an anime's id — 195 of 200 sampled rows collided with a
   real anime entry in `organized`.

## The code fix

`AniListManga` / `KitsuManga` / `MyAnimeListManga` identifiers were added, the
manga models now compute identity from the entry's own URL plus cross-source
links only (related works stay as links), `Identifiers::pairsToLinks()` is
kind-aware, and the manga MyAnimeList model parses external link hosts with
`parse_url()` rather than `explode('/', …)[0]` (which is `https:`, a substring of
every `ENTRY_URL`).

This affects new ingests only. It does not repair stored data.

## The stored-data repair

`php hiyori repair:references` recomputes `reference_ids` from the URLs each
document already stores. It is safe because of an invariant verified across all
195,946 manga documents: **`references[0]` is always the entry's own URL** (0
violations). A different `*_manga` shorthand may be adopted — that is a genuine
cross-source link — but never an anime shorthand, and never the same shorthand
twice (that is a related work, not this entry).

Applied 2026-10-19:

```
anilist_manga      scanned 65453 · updated 65373 · unchanged 80 · unresolved 0
kitsu_manga        scanned 70787 · updated 70787 · unresolved 0
myanimelist_manga  scanned 59826 · updated 59826 · unresolved 0
```

Verified after: 0 documents carry an anime key, 0 lack a `*_manga` key.

`anilist_manga` has 80 `unchanged` documents: duplicates created by a brief
ingest that ran under the new code before the existing rows were repaired. They
already hold a correct `al_manga` and are candidates for `sweep --duplicates`.

## Contaminated derived data, cleared

`organized_manga` (2,161) and the 2,161 `kind: "manga"` rows in `_hiyori_ids`
were both derived from the wrong ids and were deleted so the collection can be
rebuilt. `_counters.next` was left at 17,179; new manga ids are allocated from
there, so ids 15,018–17,178 become unused gaps. Ids are stable, not contiguous.

## Backups — rollback

| Backup collection | Rows | Holds |
|---|---|---|
| `_repair_reference_ids_backup` | 195,986 | `{collection, doc_id, old, new}` per repaired document |
| `_repair_backup_organized_manga` | 2,161 | the old `organized_manga`, verbatim |
| `_repair_backup_manga_registry` | 2,161 | the deleted `_hiyori_ids` rows, verbatim |

To reverse the reference repair, for example:

```js
db.getCollection("_repair_reference_ids_backup").find({}).forEach(b => {
  db.getCollection(b.collection).updateOne({_id: b.doc_id}, {$set: {reference_ids: b.old}});
});
```

Restoring `organized_manga` is `_repair_backup_organized_manga` → `organized_manga`
and `_repair_backup_manga_registry` → `_hiyori_ids`.

## Two problems found while rebuilding

### The cursor died at 45 %

The first rebuild attempt ran for ~2h45m and then exited with:

```
In MetadataFuzzyStrategy.php line 121:
  cursor id 2231066960713165085 not found
```

A plain server cursor is killed after roughly ten minutes idle, and a base of
this size takes hours. This is the same failure that had previously left
`organized_manga` at 2,161 (`finalize-merges.log`). All seven strategies
iterated their base with a bare `find()`; they now pass
`['noCursorTimeout' => true, 'batchSize' => 500]`.

The partial run left 24,730 documents and their registry rows, which are
correct — allocation is idempotent, so the restart re-upserts them and reuses
the same ids.

### The source collections were up to 56 % duplicate

Because `exists()` never matched, every re-run re-inserted the whole catalogue.
This was invisible until the document count stopped tracking the progress
counter:

| Collection | Rows | Distinct ids | Duplicates |
|---|---|---|---|
| `anilist_manga` | 65,453 | 28,819 | 36,634 (56 %) |
| `kitsu_manga` | 70,787 | 63,227 | 7,560 (10.7 %) |
| `myanimelist_manga` | 59,826 | 34,455 | 25,371 (42.4 %) |

The duplicates are genuine — of 40,047 duplicate groups, only 3 contain any
variation at all (2 differ in title, 1 in type). Each collection was backed up
verbatim and then reduced to one document per identity, keeping the lowest
`_id`:

| Collection | Before | After |
|---|---|---|
| `anilist_manga` | 65,453 | 28,819 |
| `kitsu_manga` | 70,787 | 63,227 |
| `myanimelist_manga` | 59,826 | 34,455 |

This halves the rebuild (ETA went from 6h27m to ~3h36m) and matters for quality,
not just speed: the duplicates are on the *candidate* side too, where they crowd
the 100-candidate matching window and can push a better match out of it.

Backups: `_repair_backup_dup_<collection>` (verbatim, pre-dedupe).

## Still outstanding

- **Rebuild `organized_manga`.** In progress against the deduped base
  (`storage/logs/rebuild-organized-manga-2.log`). It must use `--passthrough` so
  base entries that match nothing are still published — without it, `combine`
  drops them silently, which is how the collection ended up at 2,161.
  Expect ~28,819 documents, one per distinct manga.
- **Confirm coverage.** `combine` now takes `--min-coverage`; the rebuild should
  be run with it so a low-coverage run fails loudly rather than reporting success.
- **Registry verification.** `hiyori-id --verify` should be run afterwards to
  check the rebuilt ids, once the registry hardening lands.
