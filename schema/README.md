# Hiyori publish schema

Machine-readable schema and layout notes for the **relational publish** output
(`php hiyori publish`, the export API, and `php hiyori export --format relational`).

- [`hiyori.schema.json`](hiyori.schema.json) — JSON Schema (draft 2020-12) for a
  single per-entry file.

## Dump layout

A publish produces one directory (default under `storage/publish/`, e.g.
`storage/publish/hiyori-relational-20261014-120000/`) with this shape:

```
<dump>/
├── manifest.json              # per-file sha256/bytes/rows + per-kind counts + profile
├── index/
│   └── lookup.json            # flat hiyori_id -> {slug, kind, refs}
├── data/                      # `subdir` (default "data")
│   ├── anime/
│   │   └── <hiyori_id>-<slug>.json
│   ├── manga/
│   │   └── <hiyori_id>-<slug>.json
│   ├── anime.jsonl            # bulk JSONL mirror (always written)
│   ├── manga.jsonl
│   └── tables/                # denormalised CSV views of the same data
│       ├── anime.csv
│       ├── manga.csv
│       ├── entity_titles.csv
│       └── entity_references.csv
├── schema/
│   └── hiyori.schema.json     # copy of this schema
├── schema.sql                 # DDL mirroring the CSV tables
├── README.md                  # dump summary
└── CHANGELOG.md               # added/updated/removed diff vs the previous dump
```

| Artifact | Purpose |
|---|---|
| `data/<kind>/<hiyori_id>-<slug>.json` | One entry, validating against `hiyori.schema.json` (written by the `per-entry-json` profile). |
| `index/lookup.json` | `hiyori_id` → `{slug, kind, refs}`; lets clients resolve ids without walking `data/`. |
| `manifest.json` | Per-file `sha256`/`bytes`/`rows`, per-kind counts, `generated_at` and the profile used; feeds `CHANGELOG.md`. |
| `data/*.jsonl` | Bulk one-object-per-line mirror of the entries. |
| `data/tables/*.csv` | Flat, spreadsheet/SQL-friendly view of the JSON (same rows, scalar columns). |
| `CHANGELOG.md` | Added/updated/removed entries between consecutive dumps. |

## Hiyori IDs

`hiyori_id` is the stable public key of an entry.

- **Global.** A single monotonic sequence is shared by **both** kinds, so an id
  is unique across `organized` (anime) and `organized_manga` (manga) and
  `GET /api/entries/{id}` is unambiguous.
- **Immutable.** An id is allocated once and never reused or renumbered. A
  later merge of another source *into* the same entry reuses the id recorded for
  the entry's reference set instead of allocating a new one.
- **Allocated** by migration v3 (`allocate_hiyori_ids`, `php hiyori migrate`)
  or the backfill `php hiyori hiyori-id --backfill`. Both are idempotent: they
  only touch documents missing `hiyori_id`.
- **Registry.** The `_hiyori_ids` collection stores one row per id:

  ```json
  { "hiyori_id": 42, "kind": "anime",
    "refs": { "al": "1", "mal": "5114", "k": "1" },
    "slug": "fullmetal-alchemist-brotherhood",
    "legacy_id": "64f0…", "allocated_at": "…" }
  ```

  The `_counters` collection (`_id: "hiyori_id"`) hands out blocks of ids.
  `refs` and `legacy_id` are the recovery keys: `findRegistryId()` matches an
  entry to an existing id by any shared reference or legacy id.
- **Integrity.** Unique index on `hiyori_id` (registry) and on
  `kind + slug`; non-unique indexes on each `refs.<shorthand>`.
  `php hiyori hiyori-id --verify` cross-checks docs against the registry.

### Slugs

- `normalizeSlug()` lowercases the title, replaces every run of non-alphanumeric
  characters with `-`, trims `-`, and truncates to **120** characters; an empty
  result becomes `untitled`.
- Slugs are unique **per kind**; collisions get `-2`, `-3`, … appended
  (`uniqueSlug()`).

## File-name pattern

The exporter's `filename` pattern must contain `{hiyori_id}` (enforced by the
export API). Typical values:

- `{hiyori_id}-{slug}.json` (default per-entry file name)
- `{hiyori_id}.json`

Patterns with traversal (`..`, a leading `/`, or `\`) are rejected.

## Validating

```bash
# any draft-2020-12 validator, e.g. check-jsonschema / ajv
check-jsonschema --schemafile schema/hiyori.schema.json \
  storage/publish/hiyori-relational-*/data/anime/*.json
```
