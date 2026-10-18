# Explorer UX overhaul — implementation plan

> [!NOTE]
> **Status:** the Explorer page shipped (React + TanStack DataTable) with the
> The dashboard restructure; the backend wave (`/api/explore*`, `/api/histogram`,
> `/api/meta`, facets, ranges, bulk/undo/import) is complete — see
> [dashboard-roadmap.md](dashboard-roadmap.md) → “Explorer backend wave”.
> This file keeps the original UX checklist; unchecked items below are
> grooming/review work, not shipped features.

Selected via checkbox review (57 of 60; B5 server views & B8 regex toggle & F2 gallery deferred).
Decision: **detail opens in a right side-panel (Sheet)** — no separate detail page.

## Phase 1 — Columns & cells
C1 column resize · C2 pinning (title+selection left, actions right) · C3 inline micro-charts ·
C4 expandable objects · C5 image thumbs · C6 inline links · C7 empty-rate signal · C8 density
controls · C9 multi-sort
## Phase 2 — Search & filters
B1 field-scoped search · B2 range filters (episodes/year) · B3 operators (exact:/not:/or:) ·
B4 facets + counts · B6 recent searches · B7 filter chips · B9 needs-attention tab badges ·
B10 live search count (AbortController, single round-trip via facets co-fetch)
## Phase 3 — Navigation & rows
A1 URL state sync · A2 detail deep-link (hash on sheet) · A3 jump-to-page · A4 fav collections ·
A5 keyboard workflow (/, arrows, enter, esc, c) · A6 related-row nav · D1 inline editing ·
D2 context menu · D3 hover actions · D4 row favorites · D5 dup scanner · D7 arrow nav
## Phase 4 — Drawer/side panel
Sheet slide-over: structured key/value tab + raw JSON tab (F1) · ref map (F3) · per-doc issues
(F4). (F2 gallery deferred.)
## Phase 5 — Bulk & data ops
E1 bulk set field · E2 copy bulk ids/json · E3 export progress (>10k) + cancel · E4 export
selection · E5 backup guard before destructive ops · E6 CSV import (upsert by id) ·
E7 soft-delete undo (5-min restore; backend undo queue)
## Phase 6 — Performance
G1 smart debounce (300ms) · G2 row virtualization (>100 rows, TanStack Virtual) · G3 meta
facets (counts on /api/meta) · G4 page+facets single round-trip · G5 lazy images
## Phase 7 — Look & feel
H1 header polish/icons · H2 hover elevation + active tint · H3 smart empty states ·
H4 zebra + tighter borders + compact default · H5 mono toggle for id/refs · H6 per-source
dots · H7 shadowed sticky action bar when selected · H8 collection brand icons ·
H9 first-visit onboarding tooltips · H10 per-row ref-density heat strip

## Backend (shipped — see roadmap “Explorer backend wave”)
restore /api/histogram (+ ?collection=) · explore facets co-fetch + range
params · /api/explore/update (inline edit) · /api/explore/bulk (set/delete) ·
/api/explore/import (CSV upsert) · undo queue + restore · meta facets.

## Shared DataTable (shipped, additive)
- virtual rows (TanStack Virtual), stickyLeft pin, multi-sort (resizable already exists)
