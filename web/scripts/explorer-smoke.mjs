#!/usr/bin/env node
/**
 * Explorer API smoke test — no deps.
 * Hits http://127.0.0.1:8080/api/explore with an edge-case battery and
 * asserts 2xx + JSON body. Logs every 4xx/5xx/parse failure with the exact
 * query. Exit code 1 if any check fails, 0 otherwise.
 *
 * Usage: node scripts/explorer-smoke.mjs [baseUrl]
 */

const BASE = process.argv[2] ?? 'http://127.0.0.1:8080'
const COLLECTION = process.argv[3] ?? 'anilist'
const collection = COLLECTION

const cases = [
  // --- basic ---
  ['baseline collection=anilist', { collection: 'anilist', page: 1, per: 25 }],
  ['empty q', { collection, q: '', page: 1, per: 25 }],
  ['q omitted entirely', { collection, page: 1, per: 25 }],

  // --- q edge cases ---
  ['q=space', { collection, q: ' ' }],
  ['q=special chars & | ( ) " \'', { collection, q: 'a & b | c (d) "e" \'f\'' }],
  ['q=percent %200', { collection, q: '%' }],
  ['q=plus sign +', { collection, q: 'one + two' }],
  ['q=equals =x', { collection, q: 'x=y' }],
  ['q=non-ascii unicode 日本語😀', { collection, q: '日本語 😀' }],
  ['q=astral emoji only', { collection, q: '😀😀😀' }],
  ['q=regex metachars .*+?^$[]{}\\|', { collection, q: '.*+?^$[]{}\\|' }],
  ['q=backslash', { collection, q: '\\' }],
  ['q=728 chars (long)', { collection, q: 'x'.repeat(728) }],
  ['q=8192 chars (huge)', { collection, q: 'x'.repeat(8192) }],
  ['q=null-like word', { collection, q: 'null' }],
  ['q=0', { collection, q: '0' }],
  ['q=-1', { collection, q: '-1' }],

  // --- field / filter combos ---
  ['field=all', { collection, q: 'steins', field: 'all' }],
  ['field=title', { collection, q: 'steins', field: 'title' }],
  ['field=bogus', { collection, q: 'steins', field: 'bogus-field-name' }],
  ['field empty string', { collection, q: 'steins', field: '' }],
  ['filter=no_mal', { collection, filter: 'no_mal' }],
  ['filter=no_al', { collection, filter: 'no_al' }],
  ['filter=no_k', { collection, filter: 'no_k' }],
  ['filter=has_mal', { collection, filter: 'has_mal' }],
  ['filter=no_refs', { collection, filter: 'no_refs' }],
  ['filter=empty_title', { collection, filter: 'empty_title' }],
  ['filter=bogus_filter', { collection, filter: 'not_a_real_filter' }],
  ['filter=UPPER_CASE', { collection, filter: 'NO_MAL' }],
  ['filter array-ish (dup param handled by URLSearchParams)', { collection, filter: 'no_mal', filter2: 'has_mal' }],
  ['filters comma list', { collection, filters: 'no_mal,has_mal' }],
  ['filters comma list with spaces', { collection, filters: 'no_mal, has_mal' }],
  ['filters empty', { collection, filters: '' }],
  ['filters bogus', { collection, filters: 'a,b,c,,,  ' }],
  ['filter + filters together', { collection, filter: 'no_mal', filters: 'no_mal,empty_title' }],

  // --- facets weirdness ---
  ['facets=format,status', { collection, facets: 'format,status' }],
  ['facets=a,b', { collection, facets: 'a,b' }],
  ['facets=0', { collection, facets: '0' }],
  ['facets=title x', { collection, facets: 'title x' }],
  ['facets=weird chars', { collection, facets: 'x; drop table--' }],
  ['facets=empty', { collection, facets: '' }],
  ['facets=format,status,title,bogus', { collection, facets: 'format,status,title,bogus' }],
  ['format=TV', { collection, format: 'TV' }],
  ['format=non-existent', { collection, format: 'VIDEOGAME_RPG' }],
  ['status=Completed', { collection, status: 'Completed' }],
  ['status=bogus', { collection, status: 'NotAStatus' }],
  ['format+status via facets', { collection, facets: 'format,status', format: 'TV', status: 'Completed' }],
  ['regex=1 (untested param)', { collection, q: 'steins', regex: 1 }],
  ['regex=0 (untested param)', { collection, q: 'steins', regex: 0 }],
  ['regex=banana (untested param)', { collection, q: 'steins', regex: 'banana' }],

  // --- ranges ---
  ['episodes_min=1', { collection, episodes_min: '1' }],
  ['episodes_min=max (non-numeric)', { collection, episodes_min: 'max' }],
  ['episodes_min=0', { collection, episodes_min: '0' }],
  ['episodes_min=-5', { collection, episodes_min: '-5' }],
  ['episodes_min=1e999', { collection, episodes_min: '1e999' }],
  ['episodes_min=Infinity', { collection, episodes_min: 'Infinity' }],
  ['episodes_min=NaN', { collection, episodes_min: 'NaN' }],
  ['episodes_min=1e6', { collection, episodes_min: '1e6' }],
  ['episodes_min=1&episodes_max=0 (inverted)', { collection, episodes_min: '1', episodes_max: '0' }],
  ['episodes_min=5&episodes_max=5', { collection, episodes_min: '5', episodes_max: '5' }],
  ['year_min=2000', { collection, year_min: '2000' }],
  ['year_min=abc', { collection, year_min: 'abc' }],
  ['year_min=99999', { collection, year_min: '99999' }],
  ['year_min=0&year_max=1', { collection, year_min: '0', year_max: '1' }],
  ['eps/year all empty strings', { collection, episodes_min: '', episodes_max: '', year_min: '', year_max: '' }],

  // --- pagination / per ---
  ['per=0', { collection, per: 0 }],
  ['per=-5', { collection, per: -5 }],
  ['per=1000000', { collection, per: 1000000 }],
  ['per=1e10', { collection, per: 1e10 }],
  ['per=1.5 (fractional)', { collection, per: '1.5' }],
  ['per=NaN', { collection, per: 'NaN' }],
  ['per=Infinity', { collection, per: 'Infinity' }],
  ['per=abc', { collection, per: 'abc' }],
  ['page=0', { collection, page: 0 }],
  ['page=-1', { collection, page: -1 }],
  ['page=1e9', { collection, page: 1e9 }],
  ['page=999999', { collection, page: 999999 }],
  ['page=1.7 (fractional)', { collection, page: '1.7' }],
  ['page=NaN', { collection, page: 'NaN' }],
  ['page=abc', { collection, page: 'abc' }],
  ['page=0&per=0', { collection, page: 0, per: 0 }],

  // --- sort / dir ---
  ['sort=title', { collection, sort: 'title' }],
  ['sort=title.desc (dot in name)', { collection, sort: 'title.desc' }],
  ['sort=title desc (space)', { collection, sort: 'title desc' }],
  ['sort=nosuchfield', { collection, sort: 'nosuchfield' }],
  ['sort=_id', { collection, sort: '_id' }],
  ['sort=a.b.c (nested dotted)', { collection, sort: 'a.b.c' }],
  ['dir=asc', { collection, sort: 'title', dir: 'asc' }],
  ['dir=desc', { collection, sort: 'title', dir: 'desc' }],
  ['dir=bogus', { collection, sort: 'title', dir: 'bogus' }],
  ['dir=DESC (uppercase)', { collection, sort: 'title', dir: 'DESC' }],
  ['dir=1', { collection, sort: 'title', dir: 1 }],
  ['sort empty + dir bogus', { collection, sort: '', dir: 'nope' }],
  ['sort with null char', { collection, sort: 'ti\x00tle' }],

  // --- collection weirdness ---
  ['collection=anilist', { collection: 'anilist' }],
  ['collection=ANILIST (case)', { collection: 'ANILIST' }],
  ['collection=nosuchcollection', { collection: 'nosuchcollection' }],
  ['collection=empty', { collection: '' }],
  ['collection=spaces', { collection: '  ' }],
  ['collection=weird chars', { collection: 'a;b"c' }],
  ['collection=anilist&q=...', { collection: 'anilist', q: 'a&b', filter: 'no_mal', sort: 'title', dir: 'desc', facets: 'format,status', episodes_min: '5' }],

  // --- combined kitchen sink (what the UI actually sends) ---
  ['UI combo (2 filters, facets, ranges)', {
    collection, page: 2, per: 50, q: 'guren', field: 'title',
    filter: 'no_mal', filters: 'no_mal,has_mal', format: 'TV', status: 'Ongoing',
    sort: 'title', dir: 'desc', facets: 'format,status',
    epsMin: '12', epsMax: '24', yearMin: '2000', yearMax: '2010',
  }],
]

function buildUrl(params) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue
    qs.set(k, String(v))
  }
  return `${BASE}/api/explore?${qs.toString()}`
}

let failures = 0
let nonJsonOk = 0
const warnings = []

async function run() {
  for (const [label, params] of cases) {
    const url = buildUrl(params)
    let res
    try {
      res = await fetch(url, { signal: AbortSignal.timeout(20000) })
    } catch (e) {
      failures++
      console.log(`FAIL  ${label}\n      network error: ${e.message}\n      url: ${url}`)
      continue
    }
    const ok = res.status >= 200 && res.status < 300
    let body
    try {
      body = await res.json()
    } catch {
      body = null
    }
    const statusTag = ok ? 'ok  ' : 'HTTP'
    if (!ok || body === null) {
      failures++
      console.log(`${statusTag}  ${label}\n      status=${res.status} body=${body === null ? '<non-JSON>' : JSON.stringify(body).slice(0, 300)}\n      url: ${url}`)
      continue
    }
    if (body.error) {
      warnings.push({ label, status: res.status, error: body.error, url })
      console.log(`WARN  ${label}\n      status=${res.status} error="${body.error}"\n      url: ${url}`)
      continue
    }
    // sanity-check shape
    if (typeof body.total !== 'number' || !Array.isArray(body.docs)) {
      warnings.push({ label, status: res.status, note: 'no total/docs in body', url })
      console.log(`WARN  ${label}\n      status=${res.status} shape: total=${typeof body.total} docs=${Array.isArray(body.docs)}\n      url: ${url}`)
      continue
    }
    console.log(`ok    ${label}  (total=${body.total}, docs=${body.docs.length})`)
  }

  // --- also probe the extra endpoints the Explorer dialogs use ---
  console.log('\n--- adjacent endpoints ---')
  for (const ep of ['/api/explore/update', '/api/explore/bulk', '/api/explore/undo', '/api/explore/restore', '/api/explore/import', '/api/validate']) {
    try {
      const r = await fetch(`${BASE}${ep}`, ep.startsWith('/api/explore/update') || ep === '/api/explore/bulk' || ep === '/api/explore/undo' || ep === '/api/explore/import' || ep === '/api/explore/restore'
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }
        : undefined, { signal: AbortSignal.timeout(10000) })
      const text = await r.text()
      console.log(`HTTP ${r.status}  ${ep}  → ${text.slice(0, 120).replace(/\n/g, ' ')}`)
    } catch (e) {
      console.log(`ERR  ${ep}  → ${e.message}`)
    }
  }

  console.log(`\n=== RESULT: ${failures} failures, ${warnings.length} warnings ===`)
  process.exit(failures > 0 ? 1 : 0)
}

run()