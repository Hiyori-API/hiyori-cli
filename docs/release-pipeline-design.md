# HiyoriDB Compressed Release Pipeline — Design

**Status:** Implemented (Tier B command + assets). See Batch 4 checklist for the few remaining target-repo cleanup items.  
**Date:** 2026-10-15  
**Owner:** Hiyori-API / HiyoriDB maintainers  
**Companion checklist:** `docs/dashboard-roadmap.md` → *Batch 4*.
**CI secret:** `HIYORI_DB_TOKEN` (fine-grained PAT with Contents:Read/Write on `Hiyori-API/HiyoriDB`; falls back to `GITHUB_TOKEN` for same-repo operation).

---

## 0. TL;DR

1. HiyoriDB already produces a clean, content-addressed dump (`manifest.json` with per-file `sha256`
   and a top-level `content_hash`). The missing piece is **how that dump leaves the running app**
   and reaches users without exploding the Git repository.
2. **Recommendation:** move from "push the raw per-entry tree to `master` on every publish" to
   **release-only datasets** (manami-style):
   - **Tier A (management repo, `hiyori-cli`):** code, schema, config, README only. *(already the case)*
   - **Tier B (data releases):** one `hiyori-db-<tag>.tar.zst` (~10 MB today) plus optional
     `.jsonl.zst`, `.tables.zip`, `.schema.zip` attached to a versioned **GitHub Release** on
     `Hiyori-API/HiyoriDB`.
   - **Tier C (developer ergonomics):** do **not** use Git LFS for the 15,880-entry JSON tree.
     Keep a tiny `latest.json` pointer in the repo and point everyone at releases.
3. Tag releases `data-YYYY.WW` (ISO week, append `.N` on a re-run), annotated with the manifest's
   `content_hash`. Consumers pin by **tag + content_hash**, not by `latest`.
4. Add `php hiyori release` (compressed + tagged + released) alongside the existing raw-tree
   `php hiyori publish`. Add `--verify` to `publish`.
5. Raw-tree publish stays for small/emergency fixes; production uses the release path.

---

## 1. Current baseline

### 1.1 What exists today

| Artifact | Value |
|---|---|
| Source repo | `Hiyori-API/hiyori-cli` (this repo) |
| Data repo | `Hiyori-API/HiyoriDB` |
| Publish command | `php hiyori publish` / `php hiyori db:push` |
| Export engine | `Hiyori\Service\Exporter` (per-entry JSON, JSONL mirror, CSV tables, manifest) |
| Checksums | `manifest.json`: per-file `sha256`/`bytes`/`rows`, plus top-level `content_hash` (sha256 over sorted `name\0sha256`) |
| CI | `.github/workflows/publish.yml` (manual/tag trigger → migrate → combine → `combine:dupes` release gate → `php hiyori release`) |

Measured dump (`output/hiyoridb`, 2026-10-14):

| Metric | Value |
|---|---|
| Anime entries | 13,719 |
| Manga entries | 2,161 |
| Total files | 15,890 (15,880 per-entry JSON) |
| On-disk (blocks) | ~94 MB |
| On-disk (bytes) | ~50 MB |
| Per-entry JSON | 15,880 files / ~18 MB |
| `anime.jsonl` | ~13.3 MB |
| `manga.jsonl` | ~1.4 MB |
| `manifest.json` | ~2.9 MB |
| `index/lookup.json` | ~2.8 MB |
| `tar --zstd` (default) | **~9.6 MB** |
| `tar.gz` (exporter default) | ~? (`.tar.gz` sidecar already produced) |

### 1.2 Current publish flow (raw tree)

```
php hiyori publish
  └─ Exporter::write() → storage/publish/hiyori-relational-<ts>/
  └─ gitPublish()
       clone HiyoriDB (depth 1, master)
       cp dump → clone
       git add -A && commit
       git push HEAD:master
```

Every publish rewrites ~15k files in a commit. That works today, but it is exactly the
shape that GitHub starts punishing (file count, clone weight, index rebuilds) as the
catalog grows.

---

## 2. GitHub limits — what actually matters to us

These are the platform limits that shape the design. The right-hand column is the honest
assessment for HiyoriDB, not the raw documentation wording.

| Limit | Where it bites | HiyoriDB today | Matters? |
|---|---|---|---|
| **100 MB per file (web UI upload)** | Uploading a file through the GitHub web UI | Largest artifact ~2.9 MB (`manifest.json`); archive ~9.6 MB | **No** — nothing approaches it. |
| **~100 MB per file on push** | `git push` rejects new blobs above the file-size ceiling (equivalent to the web limit) | Per-entry JSON ~1–6 KB; compressed parts <10 MB | **No** for current shape. |
| **2 GB hard Git object / transport cap** | Very large single objects cannot be stored/transferred | Archive 9.6 MB | **No** — only relevant if we ever ship one giant file. Splitter keeps us safe. |
| **Repo size soft limit (~1 GB "recommended")** | GitHub warns/flages repos above ~1 GB; very large repos degrade clone/fetch | Raw tree 94 MB **plus `.git` history** of every weekly republish | **Yes, long term** — raw-tree republish accumulates duplicated blobs. |
| **File count (soft, ~100k "tooling pain")** | Clone cost, `git status`, index memory, GitHub Trees API pagination, Explorer lag | 15,890 files (~16% of the pain threshold). Growth ~thousands/yr | **Yes, mid term** — at ~16k we are already paying clone-time cost; crossing 100k is a real risk at scale. |
| **Git index working size (~150 MB class)** | Index rebuilds get expensive; large index also hurts IDEs | Index appears to be a few MB | **Low** — keep an eye if we ever track the raw tree at 100k files. |
| **Git LFS storage/bandwidth quotas** | LFS is metered; every `git lfs pull` consumes bandwidth; many small Pointer files still generate per-object LFS requests | n/a (we do not use LFS) | **Yes, as a reason not to** adopt LFS for this dataset. |
| **Release asset upload (~2 GB per file)** | `gh release upload` / UI rejects files above ~2 GB | Archive 9.6 MB | **No** — only reason to keep the splitter is defensive future-proofing. |
| **GitHub Releases bandwidth** | Release downloads are served by GitHub's CDN and are not metered the way LFS is | Asset ~9.6 MB; heavy consumers pay nothing | Favors releases over LFS. |

### 2.1 Bottom line

- **Nothing we ship today is blocked by a hard per-file limit.** The 100 MB / 2 GB numbers are red
  herrings for this dataset.
- **The limits that actually hurt us are repo size growth and file count.** Both come from
  committing the per-entry JSON tree on every publish. Removing that tree from `master` (and
  publishing it as a release asset) is the fix, and it is the manami approach.
- **Git LFS is the wrong cost trade-off** here: thousands of pointer files, per-object fetch
  requests, metered bandwidth, and migration complexity — all to preserve a browsable tree
  that releases already provide in a 9.6 MB archive.

---

## 3. Storage tiers and comparison

### 3.1 Tier definitions

- **Tier A — management repo** (`Hiyori-API/hiyori-cli`): source code, tests, schema,
  config, README, CI. **No publish artifacts.** Already enforced by `.gitignore`
  (`storage/publish/`, `output/`, `storage/repo-*/`). Target size: tens of MB, fast clone.
- **Tier B — data snapshots (releases):** immutable, versioned archives attached to
  `Hiyori-API/HiyoriDB` releases. This is where the ~15,880 per-entry JSON files live.
  A release contains:
  - `hiyori-db-<tag>.tar.zst` — full dump (per-entry JSON + JSONL + CSV + lookup + schema)
  - `hiyori-db-<tag>.anime.jsonl.zst` *(optional)* — bulk mirror for streaming consumers
  - `hiyori-db-<tag>.manga.jsonl.zst` *(optional)*
  - `hiyori-db-<tag>.tables.zip` *(optional)* — relational CSV tables
  - `hiyori-db-<tag>.schema.zip` *(optional)* — schema + README + manifest
  - `SHA256SUMS.txt` — per-asset checksums
- **Tier C — repository-facing metadata (no data):** HiyoriDB `master` keeps only:
  `README.md`, `schema/hiyori.schema.json`, `.hiyori-release/latest.json` (tag +
  content_hash + asset names + generated_at), and release notes. No per-entry files.

### 3.2 Approach comparison

| Approach | Clone size of data repo | GitHub file count | GitHub web browse | History / diff | Bandwidth cost | Verdict |
|---|---|---|---|---|---|---|
| **Trunk-only (current)** | ~94 MB working tree + history | ~16k → 100k | Excellent | Full per-file git history | Whole repo on every clone | Keep for *dev/exports*, not production. |
| **Release-only (manami)** | <5 MB (metadata) | <100 | Release page + `latest.json` | `content_hash` + archive; no per-file git diff | GitHub CDN, free for consumers; metered only on LFS | **Recommended (Tier B).** |
| **Git LFS for per-entry tree** | Tiny working tree, heavy LFS fetch | ~16k pointer files | Yes (after LFS pull) | Pointer-only diffs | Metered storage + bandwidth; per-object requests | **Not recommended.** |
| **Hybrid: release + LFS tree** | Tiny + heavy | ~16k | Yes | Two sources of truth | Highest | Only if an external consumer demands `git checkout` of the full tree. Defer. |

### 3.3 Why not LFS (in one paragraph)

LFS would let someone `git checkout` the dataset, but we already discourage `git clone` of
100k files and our consumers are better served by a single 9.6 MB archive + `content_hash`.
LFS also moves cost from "GitHub storage (free for normal git objects)" to "metered LFS
storage and bandwidth with monthly quotas", and it forces every tool (CLIs, dashboards,
notebooks) to install LFS. Release assets give us versioning, immutability, a CDN, and a
checksum contract for free. **LFS stays in the toolbox for genuinely large binaries
(media, database dumps >2 GB), not for this dataset.**

---

## 4. Tagging scheme

### 4.1 Tag grammar

| Form | When to use | Example |
|---|---|---|
| `data-YYYY.WW` | Default for every production publish (ISO week, UTC). | `data-2026.41` |
| `data-YYYY.WW.N` | Re-run of an already-released week whose `content_hash` changed (patch suffix). | `data-2026.41.1` |
| `vYYYY.MM.PATCH` | Optional human-promoted milestone (semver-ish) superseding a data tag. Only when the catalog cut deserves a name. | `v2026.10.0` |
| `latest` | **Mutable alias**, force-updated to the newest stable release commit. Convenience only. | `latest` |

A tag is always an **annotated tag** (not lightweight), so the `content_hash` lives inside
the git tag object itself, in addition to the release body.

### 4.2 Tag / release body (release notes)

```
HiyoriDB dataset 2026.41

profile: per-entry-json
content_hash: sha256:f4adea25315c5d86b97688e6b721f12f1f7b83d5f1f54ceb31c4daedbe54e578
counts: anime=13719 manga=2161 files=15890 rows=257791
generated_at: 2026-10-15T03:00:00+00:00
source_commit: 9f3d2a1 (hiyori-cli)
assets:
  hiyori-db-data-2026.41.tar.zst
  hiyori-db-data-2026.41.anime.jsonl.zst
  hiyori-db-data-2026.41.manga.jsonl.zst
  hiyori-db-data-2026.41.tables.zip
  hiyori-db-data-2026.41.schema.zip
  SHA256SUMS.txt

Verify:
  sha256sum -c SHA256SUMS.txt
  tar --zstd -tf hiyori-db-data-2026.41.tar.zst
```

The `content_hash` is the **identity of the dataset**, not the tag name. Tags are labels;
`content_hash` is the truth.

### 4.3 Reproducibility contract

- **Pinned consumer:** download by `data-YYYY.WW` (or full `.N`) and re-check `content_hash`
  against the manifest inside the archive. If they match, the dataset is bit-identical to the
  published one (modulo file mtimes inside the tar, which we normalize).
- **`latest` consumers:** must record the resolved tag + content_hash in their own lock file;
  never store "latest" as a durable reference.
- **From source:** checkout the `source_commit` recorded in the release notes, restore the same
  MongoDB state, run `php hiyori combine ...` + the release script; the resulting
  `content_hash` must agree. If it does not, the pipeline is non-deterministic — file a bug.

### 4.4 Rollback

- **Never delete a published release** unless it contains secrets. Add a corrective next tag.
- To move consumers back: publish `data-YYYY.WW.1` from the previous good dump, or retarget
  the `latest` GitHub Release via `gh release edit <tag> --latest` and force-move the
  `latest` tag.
- The mutable alias is the only thing that ever moves; data tags are immutable.

---

## 5. Compression and release pipeline

### 5.1 Stages

```
[1] build dump          php hiyori export organized --profile per-entry-json
[2] checksums manifest  (already produced by Exporter — verify, do not regenerate)
[3] verify dump         sha256sum -c manifest; size checks
[4] tar --zstd          single archive (default zstd level, ~9.6 MB)
[5] optional sidecars   anime.jsonl.zst, manga.jsonl.zst, tables.zip, schema.zip
[6] split if > 2 GB     split -b 1900M *.part + rm original (defensive)
[7] SHA256SUMS.txt      per-asset checksums
[8] clone + metadata    HiyoriDB master: README/schema/latest.json, remove raw data/
[9] annotated tag       git tag -a data-… -m (content_hash …)
[10] push tag           git push origin <tag> (immutable) + latest (force)
[11] gh release create  attach all assets, mark --latest
```

Idempotency rule: if the target tag already exists **and** its recorded `content_hash` equals
the current one, the script exits `0` without creating anything. If the tag exists but the
hash differs, the script uses the next `.N` suffix. This is what guarantees "re-running does
not duplicate releases."

### 5.2 `scripts/publish-release.sh` — skeleton (GNU/Linux)

Run from the `hiyori-cli` repo root (or set `HIYORI_ROOT`). Requires:
`php tar zstd jq git sha256sum zip split stat find sort` and optionally `gh`.

```bash
#!/usr/bin/env bash
# publish-release.sh — compressed, versioned release of the HiyoriDB dataset.
# Usage:
#   MONGODB_URL=... GITHUB_TOKEN=... GITHUB_REPO=Hiyori-API/HiyoriDB \
#     scripts/publish-release.sh
# Env knobs:
#   PROFILE=per-entry-json      INCLUDE_MANGA=1   DRY_RUN=0
#   TAG_OVERRIDE=data-2026.41   BRANCH=master      HIYORI_DUMP_DIR=/path/to/dump
set -euo pipefail

require() { command -v "$1" >/dev/null 2>&1 || { echo "missing required tool: $1" >&2; exit 127; }; }
for c in php tar zstd jq git sha256sum zip split stat find sort; do require "$c"; done

: "${MONGODB_URL:?MONGODB_URL is required}"
: "${GITHUB_REPO:?GITHUB_REPO (owner/repo or URL) is required}"

PROFILE="${PROFILE:-per-entry-json}"
INCLUDE_MANGA="${INCLUDE_MANGA:-1}"
DRY_RUN="${DRY_RUN:-0}"
BRANCH="${BRANCH:-master}"
TAG="${TAG_OVERRIDE:-data-$(date -u +%Y.%V)}"

# --- normalize repo slug -------------------------------------------------------
REPO_SLUG="${GITHUB_REPO#https://github.com/}"
REPO_SLUG="${REPO_SLUG#http://github.com/}"
REPO_SLUG="${REPO_SLUG#git@github.com:}"
REPO_SLUG="${REPO_SLUG%.git}"
REPO_SLUG="${REPO_SLUG%/}"

TOKEN="${GITHUB_TOKEN:-${GH_TOKEN:-}}"
HIYORI_ROOT="${HIYORI_ROOT:-$(pwd)}"

# --- work area ----------------------------------------------------------------
WORK="$(mktemp -d -t hiyori-release.XXXXXX)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

# --- 5.1 [1] build dump ------------------------------------------------------
if [[ -n "${HIYORI_DUMP_DIR:-}" && -f "${HIYORI_DUMP_DIR}/manifest.json" ]]; then
  DUMP_DIR="$HIYORI_DUMP_DIR"
  echo "[1/11] using provided dump: $DUMP_DIR"
else
  echo "[1/11] building dump (profile=$PROFILE)"
  EXPORT_ARGS=(export organized --profile "$PROFILE" --output "$WORK")
  if [[ "$INCLUDE_MANGA" != "1" ]]; then
    EXPORT_ARGS+=(--config entities.manga=false)
  fi
  (cd "$HIYORI_ROOT" && php hiyori "${EXPORT_ARGS[@]}")
  DUMP_DIR="$(find "$WORK" -maxdepth 1 -type d -name 'hiyori-relational-*' -printf '%T@ %p\n' \
              | sort -nr | head -n1 | cut -d' ' -f2-)"
fi
[[ -n "$DUMP_DIR" && -f "$DUMP_DIR/manifest.json" ]] || { echo "export produced no manifest" >&2; exit 1; }

# --- read manifest ------------------------------------------------------------
CONTENT_HASH="$(jq -r .content_hash "$DUMP_DIR/manifest.json")"
PROFILE_EFF="$(jq -r .profile    "$DUMP_DIR/manifest.json")"
ANIME="$(jq -r .counts.anime    "$DUMP_DIR/manifest.json")"
MANGA="$(jq -r .counts.manga    "$DUMP_DIR/manifest.json")"
FILES="$(jq -r .counts.files    "$DUMP_DIR/manifest.json")"
ROWS="$(jq -r .counts.rows      "$DUMP_DIR/manifest.json")"
GENERATED_AT="$(jq -r .generated_at "$DUMP_DIR/manifest.json")"
SOURCE_COMMIT="$(cd "$HIYORI_ROOT" && git rev-parse --short HEAD 2>/dev/null || echo unknown)"

echo "content_hash=$CONTENT_HASH counts=anime:$ANIME manga:$MANGA files:$FILES rows:$ROWS"

# --- [2-3] verify dump -------------------------------------------------------
echo "[2/11] verifying manifest checksums"
(cd "$DUMP_DIR" && jq -r '.files | to_entries[] | "\(.value.sha256 | sub("^sha256:"; ""))  \(.key)"' \
    manifest.json > "$WORK/manifest.sha256")
(cd "$DUMP_DIR" && sha256sum -c "$WORK/manifest.sha256" >/dev/null)
echo "[3/11] archive readability + size gate"
for f in "$DUMP_DIR"/*; do
  if [[ -f "$f" ]]; then
    sz="$(stat -c%s "$f")"
    if (( sz > 100*1024*1024 )); then
      echo "dump file exceeds 100 MB: $f ($sz bytes)" >&2; exit 1
    fi
  fi
done

# --- idempotency: tag + content_hash -----------------------------------------
echo "[idempotency] checking existing release for $TAG"
if command -v gh >/dev/null 2>&1; then
  if gh release view "$TAG" --repo "$REPO_SLUG" >/dev/null 2>&1; then
    EXISTING_HASH="$(gh release view "$TAG" --repo "$REPO_SLUG" --json body -q .body \
                      | sed -n 's/^content_hash: *//p' | head -n1)"
    if [[ "$EXISTING_HASH" == "$CONTENT_HASH" ]]; then
      echo "$TAG already published with identical content_hash — nothing to do."
      exit 0
    fi
    n=1
    while gh release view "${TAG}.${n}" --repo "$REPO_SLUG" >/dev/null 2>&1; do n=$((n+1)); done
    TAG="${TAG}.${n}"
    echo "content_hash changed; using new tag: $TAG"
  fi
fi

ASSET_DIR="$WORK/assets"
mkdir -p "$ASSET_DIR"

# --- [4] tar --zstd ----------------------------------------------------------
echo "[4/11] creating tar.zst"
STAGE_NAME="hiyori-db-${TAG}"
STAGE_DIR="$WORK/$STAGE_NAME"
rm -rf "$STAGE_DIR"
cp -a "$DUMP_DIR" "$STAGE_DIR"
tar --zstd -cf "$ASSET_DIR/hiyori-db-${TAG}.tar.zst" -C "$WORK" "$STAGE_NAME"
tar --zstd -tf "$ASSET_DIR/hiyori-db-${TAG}.tar.zst" >/dev/null

# --- [5] optional sidecars ---------------------------------------------------
echo "[5/11] creating sidecars"
if [[ -f "$DUMP_DIR/data/anime.jsonl" ]]; then
  zstd -q -19 -f -o "$ASSET_DIR/hiyori-db-${TAG}.anime.jsonl.zst" "$DUMP_DIR/data/anime.jsonl"
fi
if [[ -f "$DUMP_DIR/data/manga.jsonl" ]]; then
  zstd -q -19 -f -o "$ASSET_DIR/hiyori-db-${TAG}.manga.jsonl.zst" "$DUMP_DIR/data/manga.jsonl"
fi
if [[ -d "$DUMP_DIR/data/tables" ]]; then
  (cd "$DUMP_DIR" && zip -q -r "$ASSET_DIR/hiyori-db-${TAG}.tables.zip" data/tables schema/hiyori.schema.json)
fi
(cd "$DUMP_DIR" && zip -q "$ASSET_DIR/hiyori-db-${TAG}.schema.zip" schema/hiyori.schema.json README.md manifest.json)

# --- [6] split if > 2 GB (defensive: GitHub release asset cap) ----------------
echo "[6/11] splitting any asset above 1.9 GB"
for f in "$ASSET_DIR"/*; do
  [[ -f "$f" ]] || continue
  sz="$(stat -c%s "$f")"
  if (( sz > 1900*1024*1024 )); then
    split -b 1900M -d -a 3 --additional-suffix=.part "$f" "${f}."
    rm "$f"
  fi
done
for f in "$ASSET_DIR"/*; do
  [[ -f "$f" ]] || continue
  sz="$(stat -c%s "$f")"
  if (( sz > 2*1024*1024*1024 )); then
    echo "asset still exceeds 2 GB after split: $f" >&2; exit 1
  fi
done

# --- [7] per-asset checksums -------------------------------------------------
echo "[7/11] writing SHA256SUMS.txt"
(cd "$ASSET_DIR" && sha256sum ./* > SHA256SUMS.txt)

if [[ "$DRY_RUN" == "1" ]]; then
  echo "DRY RUN — assets ready in $ASSET_DIR:"; find "$ASSET_DIR" -type f -printf '  %f  %s bytes\n'
  exit 0
fi

# --- release notes ------------------------------------------------------------
NOTES="$WORK/release-notes.md"
cat > "$NOTES" <<EOF
HiyoriDB dataset ${TAG}

profile: ${PROFILE_EFF}
content_hash: ${CONTENT_HASH}
counts: anime=${ANIME} manga=${MANGA} files=${FILES} rows=${ROWS}
generated_at: ${GENERATED_AT}
source_commit: ${SOURCE_COMMIT} (hiyori-cli)

Verify:
  sha256sum -c SHA256SUMS.txt
  tar --zstd -tf hiyori-db-${TAG}.tar.zst

Assets in this release:
$(cd "$ASSET_DIR" && find . -maxdepth 1 -type f ! -name 'SHA256SUMS.txt' -printf '  %f\n' | sort)
EOF
TITLE="${TITLE:-HiyoriDB ${TAG}}"

# --- [8] clone target repo, clean raw data, update metadata -------------------
echo "[8/11] updating target repo metadata"
CLONE="$WORK/repo"
if [[ -n "$TOKEN" ]]; then
  git clone --depth 1 -b "$BRANCH" "https://x-access-token:${TOKEN}@github.com/${REPO_SLUG}.git" "$CLONE"
else
  git clone --depth 1 -b "$BRANCH" "https://github.com/${REPO_SLUG}.git" "$CLONE"
fi

# Manami-style: remove the legacy per-entry tree from master. Keep schema + README.
git -C "$CLONE" rm -r --ignore-unmatch data index manifest.json CHANGELOG.md '*.tar.gz' '*.zip' '*.zst' >/dev/null 2>&1 || true

mkdir -p "$CLONE/.hiyori-release"
cat > "$CLONE/.hiyori-release/latest.json" <<EOF
{
  "tag": "${TAG}",
  "content_hash": "${CONTENT_HASH}",
  "counts": { "anime": ${ANIME}, "manga": ${MANGA}, "files": ${FILES}, "rows": ${ROWS} },
  "profile": "${PROFILE_EFF}",
  "generated_at": "${GENERATED_AT}",
  "source_commit": "${SOURCE_COMMIT}",
  "assets": $(cd "$ASSET_DIR" && find . -maxdepth 1 -type f -printf '%f\n' | sort | jq -R . | jq -s .)
}
EOF

git -C "$CLONE" add -A
if ! git -C "$CLONE" diff --cached --quiet; then
  git -C "$CLONE" -c user.name="hiyori-bot" -c user.email="hiyori@hikarino.local" \
       commit -m "release: ${TAG} (${CONTENT_HASH})" >/dev/null
  if [[ -n "$TOKEN" ]]; then
    git -C "$CLONE" push "https://x-access-token:${TOKEN}@github.com/${REPO_SLUG}.git" "HEAD:${BRANCH}"
  else
    git -C "$CLONE" push origin "HEAD:${BRANCH}"
  fi
else
  echo "no metadata changes to commit"
fi

# --- [9] annotated tag -------------------------------------------------------
echo "[9/11] creating annotated tag ${TAG}"
git -C "$CLONE" tag -a "$TAG" -m "$(cat "$NOTES")"
if [[ -n "$TOKEN" ]]; then
  git -C "$CLONE" push "https://x-access-token:${TOKEN}@github.com/${REPO_SLUG}.git" "$TAG"
else
  git -C "$CLONE" push origin "$TAG"
fi

# --- [10] moving 'latest' alias -----------------------------------------------
echo "[10/11] updating mutable 'latest' alias (force)"
git -C "$CLONE" tag -f latest HEAD
if [[ -n "$TOKEN" ]]; then
  git -C "$CLONE" push "https://x-access-token:${TOKEN}@github.com/${REPO_SLUG}.git" latest --force
else
  git -C "$CLONE" push origin latest --force
fi

# --- [11] GitHub Release ------------------------------------------------------
if ! command -v gh >/dev/null 2>&1; then
  echo "[11/11] gh CLI unavailable — annotated tag pushed; release NOT created."
  echo "Follow up with: gh release create ${TAG} ${ASSET_DIR}/* --repo ${REPO_SLUG} --title '${TITLE}' --notes-file ${NOTES} --latest"
  exit 0
fi

echo "[11/11] creating GitHub Release ${TAG}"
gh release create "$TAG" "$ASSET_DIR"/* \
  --repo "$REPO_SLUG" \
  --title "$TITLE" \
  --notes-file "$NOTES" \
  --latest

echo "Released ${TAG} -> https://github.com/${REPO_SLUG}/releases/tag/${TAG}"
echo "content_hash=${CONTENT_HASH}"
```

Notes on this skeleton:

- `set -euo pipefail` + `trap cleanup EXIT` keeps partial runs from leaking `/tmp`.
- `tar --zstd` is supported by GNU tar ≥1.31 when `zstd` is installed (verified on the
  WSL/Ubuntu image: 9.6 MB in ~5 s).
- The `gh` branch is **optional**: without it we still get an annotated, pushed tag, which is
  the documented fallback.
- `HIYORI_DUMP_DIR` lets a PHP `ReleaseCommand` reuse an in-process dump instead of
  re-exporting.
- `INDEX/lookup.json` and `manifest.json` are intentionally **not** kept in `master`; they
  ship inside the archive. If we later want them browsable, ship only a small
  `release.json` pointer (already done) — do not resurrect the 5.6 MB per publish.

### 5.3 Why `tar --zstd` and not `--zstd -19`

Default zstd gives ~9.6 MB in 5 s on this dataset. Maximum compression (`-19`) costs
~10× the time for under 5% more space at this scale. Keep the default for CI; expose
`ZSTD_LEVEL` only if a future consumer asks. If changing, use
`ZSTD_CLEVEL=19 tar --zstd -cf …` (tar honors `ZSTD_CLEVEL`).

### 5.4 Archive determinism

To make `content_hash` reproducible across replays (same data), normalize the tar:

- Exporter already writes deterministically (sorted lookup, per-file bytes).
- Set `SOURCE_DATE_EPOCH` before tar: `export SOURCE_DATE_EPOCH=$(date +%s)` is wrong;
  instead derive from `generated_at`, or pin `SOURCE_DATE_EPOCH=0` (maps to 1970-01-01)
  so the archive bytes are stable for identical trees.
- Use `--sort=name --owner=0 --group=0 --numeric-owner --mtime="@${SOURCE_DATE_EPOCH}"`.
  Recommended invocation becomes:

  ```
  tar --sort=name --zstd --owner=0 --group=0 --numeric-owner \
      --mtime="@${SOURCE_DATE_EPOCH:-0}" -cf <out> <stage>
  ```

- Zstandard frames include no timestamp by default (`zstd` does not embed mtime unless
  asked), so only the tar headers need normalizing.

---

## 6. PublishCommand / ReleaseCommand changes

### 6.1 `publish` (raw tree) — keep, add `--verify`

`src/Commands/PublishCommand.php` keeps its current behavior for small/emergency updates.
Proposed additions (design only, no code written here):

| Option | Meaning |
|---|---|
| `--verify` | After `Exporter::write()`, validate every manifest entry with `sha256sum -c` equivalents, check no single dump file ≥ 100 MB, and archive-test the `.tar.gz` sidecar. Abort on any failure. |
| `--max-changed N` | Abort before push if `$result['changed'] > N` (default: unlimited). Used as the diff-stat gate for the raw-tree path. |
| `--force` | Bypass `--max-changed`. |
| `--no-manga` | existing |
| `--dry-run` | existing |

PHP snippet for the gate (to be dropped into `PublishCommand`):

```php
private function verifyDump(string $dir): void
{
    $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
        throw new \RuntimeException('manifest.json missing or invalid');
    }
    foreach ($manifest['files'] as $rel => $meta) {
        $hash = hash_file('sha256', $dir . '/' . $rel);
        $expected = (string) ($meta['sha256'] ?? '');
        if ($hash !== $expected) {
            throw new \RuntimeException("checksum mismatch: {$rel}");
        }
        if ((int) ($meta['bytes'] ?? 0) !== filesize($dir . '/' . $rel)) {
            throw new \RuntimeException("size mismatch: {$rel}");
        }
    }
    foreach (['.tar.gz'] as $suffix) {
        $archive = $dir . $suffix; // adjust to actual sidecar path if needed
        if (is_file($archive) && 0 !== (int) shell_exec('gzip -t ' . escapeshellarg($archive) . ' 2>&1; echo $?')) {
            throw new \RuntimeException("archive corrupt: {$archive}");
        }
    }
}
```

And before `gitPublish()`:

```php
if ($result['changed'] > $maxChanged && !$force) {
    $io->error(sprintf('diff gate: %d files changed (limit %d); use --force to override.', $result['changed'], $maxChanged));
    return Command::FAILURE;
}
```

### 6.2 New `release` command (`php hiyori release` / alias `db:release`)

Proposed signature:

```
php hiyori release [options]
  --repo=...          target repo (default GITHUB_REPO or Hiyori-API/HiyoriDB)
  --branch=master
  --tag=...           override data-YYYY.WW (optional; usually auto)
  --profile=...       export profile (default per-entry-json)
  --no-manga
  --draft             create as draft release
  --verify            run the checksum/diff gate before releasing (on by default for release)
  --dry-run           build + compress + verify, print plan, push nothing
  -m, --message=...   commit message override for the metadata commit
```

Behavior:

1. Load `ExportConfig`, call `Exporter::write()` (or accept `--dump-dir`).
2. Run `verifyDump()`; on failure abort with non-zero exit.
3. Export `HIYORI_DUMP_DIR=<dump>` and invoke `scripts/publish-release.sh`
   (PHP stays in charge of config/env; bash owns git/gh). Return the script's exit code.
4. Print a one-line summary: tag, content_hash, asset count, URL (parsed from script output).

Rationale for a **thin PHP wrapper over the shell script**: all the crypto, Git and
`gh` logic already lives in one place and is testable with `DRY_RUN=1`. Duplicating it in
PHP would create two sources of truth. The alternative (a pure PHP implementation via
`symfony/process`) is fine but is future work; the wrapper keeps scope small.

### 6.3 Why two commands instead of one

- `publish` is the "I know what I'm doing, push the raw tree" escape hatch — useful for
  hotfixes and for the transition period.
- `release` is the production path and never touches the per-entry tree in `master`.
- Keeping them separate means a CI typo (`publish` vs `release`) is visible in review, and
  `publish` can be retired gradually (see §8).

---

## 7. Rollback and version checklist

### 7.1 For consumers

- [ ] List releases: `gh release list --repo Hiyori-API/HiyoriDB`
- [ ] Download pinned: `gh release download data-2026.41 --repo Hiyori-API/HiyoriDB --dir ./hiyori-db`
- [ ] Verify: `cd hiyori-db && sha256sum -c SHA256SUMS.txt`
- [ ] Confirm `content_hash` matches the one in `release-notes` / `.hiyori-release/latest.json`.
- [ ] Read the manifest: `tar --zstd -xf hiyori-db-data-2026.41.tar.zst ... manifest.json` then `jq .counts`.
- [ ] Streaming consumers: use `.jsonl.zst` sidecar, decompress with `zstd -d`.

### 7.2 For maintainers (rollback)

- [ ] **Do not** delete releases unless they contain secrets. Publish the next `.N` tag.
- [ ] Move the `latest` GitHub release: `gh release edit <good-tag> --latest --repo Hiyori-API/HiyoriDB`
- [ ] Move the mutable `latest` tag: `git push origin latest --force` (after retagging locally).
- [ ] Record the incident: which content_hash was bad, which tag replaced it, and in the
  changelog of the next release.
- [ ] For a storage leak (secrets): delete the release + tag with
      `gh release delete <tag> --yes --cleanup-tag`, rotate the secret, republish.
- [ ] Repository rollback of `latest.json`: `git revert` the metadata commit on `master`.

### 7.3 Reproducing a release from source

- [ ] Read `source_commit` and `content_hash` from the release notes.
- [ ] `git -C hiyori-cli checkout <source_commit>`
- [ ] Restore the same MongoDB state (snapshot or `mongorestore`).
- [ ] Run the combine steps used in CI (anime → `organized`, manga → `organized_manga`).
- [ ] `DRY_RUN=1 scripts/publish-release.sh` and compare `content_hash` to the released one.
- [ ] On mismatch: check exporter/profile version and combiner auto-export flags; open an issue.

---

## 8. Migration plan from raw-tree publishing

1. **Approve this design** + roadmap Batch 4 items.
2. Land `scripts/publish-release.sh` (from §5.2), review as a normal PR (shell only, no PHP).
3. Add `php hiyori release` wrapper (thin, per §6.2) + `--verify`/`--max-changed` on `publish`.
4. Run `php hiyori release --dry-run` against a staging repo to confirm asset sizes and timings.
5. Cut the first real release tag (e.g. the next weekend's data).
6. Manami-style cleanup: run one final `publish` to remove the per-entry tree, or rely on the
   script's `git rm data index manifest.json …` in §8 of the skeleton. Keep `schema/`,
   `README.md`, `.hiyori-release/latest.json`.
7. Update `.github/workflows/publish.yml`: replace
   `php hiyori publish --repo "$GITHUB_REPO" --branch master`
   with `php hiyori release --repo "$GITHUB_REPO" --branch master` (or call the script), and
   add a weekly cron `0 3 * * 1`.
8. Update README/consumer docs with the download + verify commands from §7.1.
9. After one stable release cycle, deprecate `publish` (keep it for one minor version, print
   a warning, do not remove until a announced date).
10. Re-evaluate Tier C / LFS only if a second consumer explicitly needs `git checkout` of
    the full tree — revisit annually.

---

## 9. Non-goals

- No re-encoding of the dataset format; per-entry JSON/JSONL/CSV stay as they are.
- No committed per-entry files in `master` after migration; no LFS migration now.
- No new checksum algorithm: reuse `Exporter::contentHash()` (`sha256:` prefix).
- No change to `Exporter`'s public interface; export profiles persist.
- No deletion of historical raw-tree commits; Git history keeps them, but new clones stay
  shallow (`--depth 1`).

---

## 10. Appendix — environment variables

| Var | Required | Default | Notes |
|---|---|---|---|
| `MONGODB_URL` | yes | — | Mongo used by `php hiyori export` |
| `GITHUB_TOKEN` / `GH_TOKEN` | yes for push/release | — | fine-grained PAT: Contents read/write on HiyoriDB |
| `GITHUB_REPO` | yes | — | `owner/repo` or full HTTPS URL |
| `PROFILE` | no | `per-entry-json` | passed to export + release |
| `INCLUDE_MANGA` | no | `1` | set `0` to ship anime only |
| `DRY_RUN` | no | `0` | build + compress + verify, no push |
| `TAG_OVERRIDE` | no | `data-YYYY.WW` | force a tag name |
| `BRANCH` | no | `master` | target branch |
| `TITLE` | no | `HiyoriDB <tag>` | release title |
| `HIYORI_DUMP_DIR` | no | — | skip export, reuse an existing dump (PHP wrapper) |
| `HIYORI_ROOT` | no | `pwd` | root for `php hiyori` invocation |
| `SOURCE_DATE_EPOCH` | no | `0` | deterministic tar normalizer |

Critical constants:

- Release tag: `data-YYYY.WW` (append `.N` on hash change)
- content-hash prefix: `sha256:` (71 chars incl. prefix; see `Exporter::contentHash()`)
- Max asset size before split: 1.9 GB; hard reject above 2 GB
- Per-file repo ceiling that matters: 100 MB (we never approach it)
- Archive we ship today: ~9.6 MB (zstd default) from a ~94 MB / 15,890-file dump
