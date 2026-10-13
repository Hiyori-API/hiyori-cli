# Explorer Crash Report — QA pass

**Date:** 2026-10-13 · **Scope:** `src/components/pages/ExplorerPage.tsx`, `src/components/explorer/*`, `src/lib/explorer/*`, `src/components/data-table/data-table.tsx` (static deep review) + live API battery vs `http://127.0.0.1:8080/api/explore` + adjacent endpoints.
**Environment:** Node 22 (no deps) — **no headless chromium / puppeteer / playwright present** (`which chromium chromium-browser google-chrome` → empty; `package.json` has none; `@testing-library` absent), so live click-through was **not** possible. Findings below rest on static analysis + direct API probing.

**Ownership respected:** nothing in `src/`, `public/`, or teammate-owned files was modified. Added only `scripts/explorer-smoke.mjs` (kept) and this report.

---

## Verdict

**There ARE crash-producing paths in the Explorer**, but they are **data‑dependent** (corrupt/legacy `localStorage`, and specific `/api/validate` payload shapes), **not** unconditional from fresh state. There are also several **silent logic failures on the exact filter buttons the user names**. The one mounting crash (malformed `hiyori-saved-queries`) is the most plausible match for "crashes" seen on the Views/filter surface.

---

## 1. CRASH findings

### C1 — Malformed `hiyori-saved-queries` whitescreens the Explorer on load
- **Severity: crash** (page-load, whole Explorer render throws — no error boundary in the tree).
- **File:line** — `src/components/pages/ExplorerPage.tsx:90-92` (parse), `src/components/explorer/toolbar.tsx:217-223` (render).
- **Trigger:** any prior value of `localStorage['hiyori-saved-queries']` that is valid JSON but **not an array of `{name,collection,q,filters}` objects** — e.g. legacy shape `{"myview": {...}}`, `"a string"`, `42`, JSON `null`, or an array containing `null`/`42`/`{}`. The `useState` initializer only `try/catch`es *parse* errors (`ExplorerPage.tsx:91`); it never checks `Array.isArray` or item shape (compare: `loadFavs`/`loadRecents` in `favorites.ts:11-16,44-49` DO validate).
- **Root cause:** `saved = JSON.parse(...) ?? '[]'` returns the wrongly-typed value verbatim. `ViewsMenu` is rendered unconditionally in the toolbar and runs `{saved.length === 0 && …}` then `saved.map(s => …)` (`toolbar.tsx:218`)
  - non-array → `saved.length` on string/number/null → `undefined`/TypeError, or `saved.map is not a function` → **TypeError during render → blank page**.
  - array of garbage → `s.name`/`s.q`/`s.filters` on `null`/number → TypeError.
- **Fix:** sanitize in the initializer exactly like `loadRecents`:
  `const arr = JSON.parse(...); if (!Array.isArray(arr)) return []; return arr.filter(x => x && typeof x === 'object' && typeof x.name === 'string' && typeof x.q === 'string' && (x.filters == null || Array.isArray(x.filters)))` — or add a `normalizeSaved()` in `favorites.ts` (not owned) and call it from the page.

### C2 — Loading a saved query whose `filters`/`q` aren't the expected types crashes on the next render/effect
- **Severity: crash** (event-triggered; same white-screen class).
- **File:line** — `ExplorerPage.tsx:422-429` (`loadQuery`), crash points: `ExplorerPage.tsx:199` `const filtersKey = filters.join(',')` (runs every render) and `ExplorerPage.tsx:213` `filters.length > 1 ? filters.join(',')`; also `ExplorerPage.tsx:192` `parseQueryTerm(q)` → `search-parse.ts:30` `raw.trim()`.
- **Trigger:** Views menu → load a saved entry with `filters: "no_mal,has_mal"` (string, the shape older versions stored) → `setFilters(s.filters ?? [])` puts a **string** into `filters` state → next render `filters.join is not a function` → TypeError. Likewise a numeric/blank `q` → `parseQueryTerm(q)` → `(123).trim is not a function` in the debounce effect (`ExplorerPage.tsx:188-196`).
- **Root cause:** `s.filters ?? []` only guards `null`/`undefined`, not non-array values; `s.q` is assumed a string.
- **Fix:** coerce inside `loadQuery`: `setFilters(Array.isArray(s.filters) ? s.filters.filter(f => typeof f === 'string') : [])` and `setQ(typeof s.q === 'string' ? s.q : '')`.

### C3 — Detail-sheet validation panel can throw on non-array error values / non-string titles
- **Severity: crash** (sheet open for an affected doc).
- **File:line** — `src/components/explorer/detail-sheet.tsx:210` `{msgs.join('; ')}`; also `detail-sheet.tsx:173` `normTitle(e.title ?? '')` → `normTitle` at `detail-sheet.tsx:31-33` calls `s.toLowerCase()`.
- **Trigger:** open any row's sheet while `/api/validate`'s tail contains an event with `errors.foo` = non-array (a bare string, object) or a non-string `title`. Current live payload (`/api/validate`) shows only `errors.synonyms: [ "synonyms must be an array of strings" ]` with string titles, so this is latent — but the code (and the `ValidationEvent` type, `errors?: Record<string,string[]>` in backend.ts:129, unvalidated at runtime) assumes both shapes.
- **Root cause:** untyped backend contract; `msgs.join` and `s.toLowerCase()` called without guards.
- **Fix (not in teammate-owned files? detail-sheet IS explorer-owned by teammate — report only):** `Array.isArray(msgs) ? msgs.join('; ') : String(msgs)`; `typeof e.title === 'string' ? normTitle(e.title) : ''`.

---

## 2. ERROR findings (recoverable — console exceptions, feature aborts, no unmount)

### E1 — Unguarded `localStorage.setItem` in save/delete-query paths
- **Severity: error**
- **File:line** — `ExplorerPage.tsx:418` (`saveQuery`), `ExplorerPage.tsx:761` (Views menu delete).
- **Trigger:** `localStorage` full/quota-exceeded (or disabled) when pressing Views → 💾 save, or the ✕ next to a saved query. `persistEq` (`:129`) wraps `setItem` in try/catch; these two don't → `QuotaExceededError` thrown out of the click handler; the save silently fails (React 19 doesn't unmount on event-handler throws, but the console shows the exception and the feature doesn't work).
- **Fix:** wrap both `localStorage.setItem(...)` calls in try/catch like `persistEq`.

### E2 — `#per=0` (craftable URL) → `pageCount = Infinity` → broken pagination UI
- **Severity: error** (not a hard crash — TanStack v9's `table_getPageCount`/`setPageIndex` explicitly special-case `Infinity`, verified in `node_modules/@tanstack/table-core/dist/features/row-pagination/rowPaginationFeature.utils.js` — no `new Array(Infinity)` is reached on the render path).
- **File:line** — `ExplorerPage.tsx:703` `pageCount={Math.max(1, Math.ceil(totalShown / per))}`; feeding values from `parseHash` (`url-state.ts:91-93`, which accepts any finite number — incl. `0`, `-5`, `1.5`).
- **Trigger:** navigate to `#per=0` (or `#per=1.5`). `Math.ceil(total/0) = Infinity` → footer prints `page 1/Infinity`, **next never disables** (endless paging), backend returns 1 doc/page (verified: `per=0` → `docs=1`). `per=1.5` → `pageSize=1.5`, per Select shows blank.
- **Fix:** clamp in `parseHash` (`page`,`per` to integers, `per` ∈ [1,500], `page` ≥ 1) or clamp in `pageCount` (`Math.max(1, Math.ceil(totalShown / Math.max(1, per)))`).

---

## 3. LOGIC findings (no throw, but the named buttons don't do what users expect)

### L1 — Format/Status facet chips and the `format`/`status` params are **silent no-ops**
- **Severity: logic** (user-facing "filter buttons do nothing").
- **File:line** — `ExplorerPage.tsx:207-208` builds `format:`/`status:` chips, `ExplorerPage.tsx:746-747` renders the facet menus, `ExplorerPage.tsx:620-623` `facetPick`.
- **Evidence (live API):** `format=TV` → total stays **15015**; `status=Completed` → **15015**; `status=finished` (the actual facet bucket) → **15015**; `format=Movie,TV` → 15015. The backend ignores both params entirely. Facet chips never re-filter the page client-side either, so clicking a Format/Status facet item only adds a chip + URL change — the row set never changes.
- Also **`explorer-page:746-747`**: `{facets?.format && <FacetMenu …/>}` — the live API returns `facets.format: []`, which is truthy → an empty "Format" menu header renders (cosmetic).
- **Fix (backend-owned, report only):** implement `format`/`status` server filters (or filter client-side from facet groups).

### L2 — Multi-filter chips: only the first chip is applied; the `filters` list param is ignored
- **Severity: logic**
- **File:line** — `ExplorerPage.tsx:212-213` (`filter: prebuilt[0]` + `filters: filters.join(',')`), footer hint at `:720-722` claims "older backends apply the first filter only".
- **Evidence (live API):** `filters=no_mal` alone → **15015** (param ignored entirely); `filter=no_mal` → 255. So with 2+ prebuilt chips the *first* chip works only because the singular `filter` is sent alongside; `no_mal,has_mal` (impossible-AND) correctly yields 0 only in that combo. The "combine filters" affordance (toolbar.tsx:137) is misleading — combining has no effect beyond the first chip.
- **Fix (backend-owned):** make the `filters` param an AND-list, or document that multi-filter chips are display-only.

### L3 — `#page=N` deep links are always reset to page 1 on mount
- **Severity: logic** (contradicts the file's own "shareable/back-forwardable" comment, url-state.ts:6).
- **File:line** — `ExplorerPage.tsx:201` `useEffect(() => { setPage(1) }, [collection, qDebounced, field, filtersKey, rangesKey])` runs on first mount. Combined with the seq-guarded fetch (`:203-238`), a URL like `#page=42` fires one fetch at page 42 (discarded) then a fetch at page 1 (wins). Deep entries always land on page 1.
- **Fix:** skip the reset on first render (e.g. a `didMount` ref, or drop `page` from the initial state — keep only subsequent filter/collection changes).

### L4 — Undo after **inline edit** uses a stale closure (wrong refetch params)
- **Severity: logic**
- **File:line** — `ExplorerPage.tsx:355-365` (`undoOptions`), `:331-353` (`onCommitEditCb` memoized on `[collection]` only), `:367-376` (`forceRefetch`).
- **Trigger:** Edit a cell → success toast "Undo" → `onClick` calls the `forceRefetch` captured when `onCommitEditCb` was created (first render), so it refetches with the **original** page/q/filters/sort instead of current. Delete's undo (`done`, not memoized) uses a fresh closure — inconsistent. Worst case: after paging then undoing an edit, you're thrown back to an old page/filters view.
- **Fix:** move `undoOptions` construction into `useMemo`/`useCallback` with the real deps, or have `onCommitEditCb` accept the options / read refs at click time.

### L5 — `2xx + {error}` responses masquerade as success
- **Severity: logic**
- **File:line** — `backend.ts:71-79` returns the 200 body with `error`; `ExplorerPage.tsx:218-221` and `:374` treat it as data (`total` undefined → 0 docs, empty state "No documents match" with no error text).
- **Evidence (live API):** `collection=  ` and `collection=a;b"c` → `200 {"error":"bad collection"}` (also `collection=` empty → full dump of another collection, see L6). So a bad collection silently renders as "no results".
- **Fix:** in the page's fetch handlers, `if (res.error) { toast/diagnostic }` instead of setting data.

### L6 — `#collection=` (empty) / weird collection names are accepted and hit wrong data
- **Severity: logic** (guards missing at the boundary, though the page's own tabs always pass a valid name).
- **File:line** — `url-state.ts:78-103` accepts any `collection` string; backend `collection=` empty returns all rows (verified `collection=` → 200 total=15015; `collection=spaces` → `error:"bad collection"`).
- **Fix:** validate `collection` against `meta` before fetching (page-level state guard).

### L7 — `reference_ids` as an array renders bogus `0:foo` chips
- **Severity: logic**
- **File:line** — `cells.tsx:239` routes any `reference_ids` value to `RefAccordion`; `cells.tsx:134-135` runs `Object.entries(v)` on arrays too → chips keyed `"0"`, `"1"` with `externalUrl('0','a') === null` (no links).
- **Fix:** in `RefAccordion`, early-return `·` when `Array.isArray(v)` (or handle as a string list).

### L8 — `#cols=actions` / `#cols=select` create duplicate column ids
- **Severity: logic**
- **File:line** — URL `cols` feed `buildColumns(cols, ctx)` (`ExplorerPage.tsx:75`, `cells.tsx:267-317`) while `cells.tsx:319-364` always appends a *display* column `id: 'actions'`. A crafted `#cols=actions,title` yields two `id:'actions'` columns → duplicated header/cell keys, sticky-column layout confusion, sorting/visibility toggles mis-fire on the wrong column.
- **Fix:** filter `cols` to exclude the reserved ids (`select`, `actions`) before building columns.

---

## 4. API smoke results (`scripts/explorer-smoke.mjs`, ~95 cases + 6 adjacent endpoints)

**Result: 0 hard failures (0 non-2xx, 0 unparseable bodies).** 2 warnings (`200 {"error":"bad collection"}` for `collection=  ` and `collection=a;b"c`) — see L5.
Full run output is reproducible with `node scripts/explorer-smoke.mjs` (kept in repo; exit code = #failures).

Notable observations from the battery:
- **q edge cases:** empty/space/special chars (`& | ( ) " '`), `%`, `+`, `=` , `日本語😀`, emoji-only, regex metachars `.*+?^$[]{}\|`, backslash, 728-char and 8192-char queries — **all 2xx**, server never 500s and appears to treat `q` literally (regex metachars → 0 hits, no regex-injection throw).
- **`regex=1/0/banana`:** accepted, **no observable effect** (untested param is safely ignored — same totals as plain `q`). Recorded, not a problem.
- **`facets` weirdness** (`a,b`, `0`, `title x`, `x; drop table--`): all 2xx, facet output only present for known axes (`status` populated, `format` → `[]` fatally for the Format menu, see L1).
- **ranges:** non-numeric (`max`, `abc`, `Infinity`, `NaN`) → ignored harmlessly (2xx); numeric bounds filter correctly (`episodes_min=1e6` → 0 docs, 2xx).
- **pagination:** `per=0/-5/1000000/1e10/1.5/NaN/Infinity/abc` and `page=0/-1/999999/1e9/NaN` — all 2xx; `per≤0` → 1 doc (backend clamps), huge `page` → 0 docs. No 500. (See E2 for the client-side `pageCount=Infinity` issue.)
- **sort/dir:** dotted (`title.desc`, `a.b.c`), spaces, bogus field, null byte, `dir=bogus/DESC/1` — all 2xx.
- **adjacent endpoints:** `/api/explore/update`, `bulk`, `undo`, `import` → 400 with descriptive JSON on empty payloads (gracefully surfaced as `{error}` by `postJson`); `/api/explore/restore` → 404 (not used by the Explorer page). `/api/validate` → 200 array.

---

## 5. What to fix first (recommended order)

1. **C1** — `saved` sanitization in `ExplorerPage.tsx:90-92` (silent white-screen on corrupt legacy storage; the most likely "it crashes" trigger). Also **C2** at `loadQuery`.
2. **C3** — guard `msgs.join` / `normTitle` in `detail-sheet.tsx` (cheap, removes a latent sheet crash).
3. **E2** — clamp `page`/`per` in `parseHash` and/or `pageCount` (craftable broken pagination).
4. **L1/L2** — facet + multi-filter chips are the *named* buttons and currently don't change results (backend side; frontend `facetPick` could pre-filter client-side as a stopgap).
5. **L3** — page-reset-on-mount for deep links; **L4** — stale undo closure; **E1** — unguarded `setItem`.
6. The rest (L5-L8) are polish/edge; no crash.

---

## 6. Evidence notes / non-issues checked (so they're not re-investigated)
- `new URL(raw)` in `resolveRef` (`ExplorerPage.tsx:436`) is try/catch-wrapped — safe.
- No `new RegExp(...)` from user input anywhere in the Explorer path — no regex-injection crash.
- All `Set` state (`sel`, `favs`) is created/updated via Set-only functions — no `Set.prototype` misuse.
- `numericMax`/`emptyRate` guard empty arrays; no `Math.max/min([])` crash.
- TanStack v9 pagination special-cases `Infinity` pageCount (verified in dist) — `per=0` degrades, doesn't blow up.
- Base UI Select wrappers all null-guard `onValueChange` (`v ?? …`, `if (v) …`) — no `null` select crash.
- `history.replaceState` (both call sites) wrapped/within try/catch; `urlFor` emits fragment-only URLs.
- Row-click / row-actions dropdown / favorites pin / dup scan / bulk bar / delete / sheet open-close paths are otherwise sound in the current snapshot (with the C1-C3/L-issues above noted).

**Runtime click-through note:** no browser automation was possible in this environment (no chromium binaries, no puppeteer/playwright dep). Recommend a follow-up with the Vite dev server (`:5173`) + a headed browser for the C1/C2 toast-level reproductions.