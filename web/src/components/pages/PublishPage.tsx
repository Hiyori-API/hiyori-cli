import { useCallback, useEffect, useState } from 'react'
import {
  AlertTriangle, Check, Copy, FileJson, FolderTree, Info, Loader2, PackageOpen,
  Play, RotateCcw, Save, ShieldAlert, SlidersHorizontal, Terminal, Upload,
} from 'lucide-react'
import { toast } from 'sonner'
import { cn } from 'cn'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import {
  EXPORT_PROFILES, ExportApiError, dryRunExport, fetchExportConfig, previewExport,
  resetExportConfig, runExport, saveExportConfig,
  type ExportConfig, type ExportDryRunResponse, type ExportFieldFlags, type ExportKind,
  type ExportPreviewResponse, type ExportProfile, type ExportRunResponse, type ExportState,
} from '@/lib/api'
import { fmtCompact } from '@/lib/format'
import { PageHead, Section } from '../widgets'

/** Client-side defaults mirroring the server template (used before it loads / when offline). */
const DEFAULT_CONFIG: ExportConfig = {
  profile: 'per-entry-json',
  entities: { anime: true, manga: true },
  filename_pattern: '{hiyori_id}-{slug}',
  fields: { titles: true, synonyms: true, references: true, links: true, tags: false, genres: false, synopsis: false, score: false },
  index: true,
  pretty: true,
  bundle: false,
  subdir: '',
}

/** Which field flags exist today vs. the not-yet-populated schema v2 set. */
const FIELD_ROWS: Array<{ key: keyof ExportFieldFlags; label: string; desc: string; v2?: boolean }> = [
  { key: 'titles', label: 'Titles', desc: 'romaji / english / native' },
  { key: 'synonyms', label: 'Synonyms', desc: 'alternate titles' },
  { key: 'references', label: 'References', desc: 'external ids per entity' },
  { key: 'links', label: 'Links', desc: 'streaming / external links' },
  { key: 'tags', label: 'Tags', desc: 'schema v2 field', v2: true },
  { key: 'genres', label: 'Genres', desc: 'schema v2 field', v2: true },
  { key: 'synopsis', label: 'Synopsis', desc: 'schema v2 field', v2: true },
  { key: 'score', label: 'Score', desc: 'schema v2 field', v2: true },
]
const FIELD_DEFAULTS: Record<string, boolean> = { titles: true, synonyms: true, references: true, links: true }

const PUBLISH_CMD = 'php hiyori publish'

function apiStatus(e: unknown): number {
  return e instanceof ExportApiError ? e.status : -1
}

/** Human explanation for a failed export call — 404/0 mean the backend is not up yet. */
function describeError(e: unknown): string {
  if (e instanceof ExportApiError) {
    if (e.status === 0) return 'the API could not be reached'
    if (e.status === 404) return 'endpoint not found (404) — the export API is still being built'
    if (e.status === 409) return e.message || 'a combine run is in progress'
    return e.message
  }
  return e instanceof Error ? e.message : String(e)
}

function formatBytes(n?: number): string {
  if (n === undefined || n === null || Number.isNaN(n)) return '—'
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / 1048576).toFixed(1)} MB`
}

/** Inline amber notice shown when a call 404s / the server is unreachable. */
function BackendNotice({ title, message, onRetry }: { title: string; message: string; onRetry?: () => void }) {
  return (
    <div role="alert" className="flex items-start gap-3 rounded-xl border border-warn/40 bg-warn/5 p-4">
      <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warn" />
      <div className="min-w-0 flex-1 text-xs">
        <p className="font-medium text-warn">{title}</p>
        <p className="mt-0.5 text-muted-foreground">{message}</p>
        <p className="mt-1 text-muted-foreground">
          Expected endpoints: <code className="rounded bg-muted px-1">GET/POST /api/export-config</code>,{' '}
          <code className="rounded bg-muted px-1">GET /api/export/preview</code>,{' '}
          <code className="rounded bg-muted px-1">GET /api/export/dry-run</code>,{' '}
          <code className="rounded bg-muted px-1">POST /api/export/run</code>. The form stays editable; save, dry-run and run light up once the API is deployed.
        </p>
      </div>
      {onRetry && (
        <Button size="sm" variant="outline" onClick={onRetry}>
          <RotateCcw className="mr-1.5 h-3.5 w-3.5" />Retry
        </Button>
      )}
    </div>
  )
}

type BackendStatus = 'checking' | 'ready' | 'missing'

export function PublishPage() {
  const [backend, setBackend] = useState<BackendStatus>('checking')
  const [backendError, setBackendError] = useState('')
  const [cfg, setCfg] = useState<ExportConfig>(DEFAULT_CONFIG)
  const [template, setTemplate] = useState<ExportConfig | null>(null)
  const [state, setState] = useState<ExportState>({})
  const [saving, setSaving] = useState(false)
  const [runConflict, setRunConflict] = useState(false)

  const [previewKind, setPreviewKind] = useState<ExportKind>('anime')
  const [preview, setPreview] = useState<{ loading: boolean; data?: ExportPreviewResponse; error?: string }>({ loading: false })

  const [dry, setDry] = useState<{ loading: boolean; data?: ExportDryRunResponse; error?: string }>({ loading: false })
  const [running, setRunning] = useState(false)
  const [run, setRun] = useState<{ data?: ExportRunResponse; error?: string }>({})

  const [publishOpen, setPublishOpen] = useState(false)

  const loadConfig = useCallback(() => {
    void fetchExportConfig()
      .then(res => {
        if (res.error) {
          setBackend('missing')
          setBackendError(res.error)
          return
        }
        setCfg({ ...DEFAULT_CONFIG, ...(res.config ?? res.template ?? {}) })
        setTemplate(res.template ?? null)
        setState(res.state ?? {})
        setBackend('ready')
      })
      .catch(err => {
        setBackend('missing')
        setBackendError(describeError(err))
      })
  }, [])

  useEffect(() => { loadConfig() }, [loadConfig])

  const retryConfig = () => {
    setBackend('checking')
    setBackendError('')
    loadConfig()
  }

  const loadPreview = useCallback((kind: ExportKind, profile?: string) => {
    setPreview({ loading: true })
    void previewExport(kind, profile)
      .then(data => setPreview({ loading: false, data }))
      .catch(err => setPreview({ loading: false, error: describeError(err) }))
  }, [])

  const profile: ExportProfile = typeof cfg.profile === 'string' && cfg.profile ? (cfg.profile as ExportProfile) : 'per-entry-json'

  // Live preview follows the selected kind + profile, but only once the API answers.
  useEffect(() => {
    if (backend !== 'ready') return
    loadPreview(previewKind, profile)
  }, [backend, previewKind, profile, loadPreview])

  const entityOn = (k: ExportKind): boolean => cfg.entities?.[k] ?? true
  const setEntity = (k: ExportKind, v: boolean) => setCfg(c => ({ ...c, entities: { ...(c.entities ?? {}), [k]: v } }))

  const fieldOn = (k: keyof ExportFieldFlags): boolean => cfg.fields?.[k] ?? FIELD_DEFAULTS[k] ?? false
  const setField = (k: keyof ExportFieldFlags, v: boolean) => setCfg(c => ({ ...c, fields: { ...(c.fields ?? {}), [k]: v } }))

  const pattern = cfg.filename_pattern ?? ''
  const patternInvalid = !pattern.includes('{hiyori_id}')
  const noEntities = !entityOn('anime') && !entityOn('manga')
  const configInvalid = patternInvalid || noEntities

  const persist = (next: { config?: ExportConfig; reset?: boolean }, okMsg: string) => {
    setSaving(true)
    const call = next.reset ? resetExportConfig() : saveExportConfig({ config: next.config })
    void call
      .then(res => {
        if (res.error) {
          toast.error(res.error)
          return
        }
        const merged = res.config ?? res.template
        if (merged) setCfg({ ...DEFAULT_CONFIG, ...merged })
        if (res.template) setTemplate(res.template)
        if (res.state) setState(res.state)
        setBackend('ready')
        setBackendError('')
        toast.success(okMsg)
      })
      .catch(err => toast.error(`${next.reset ? 'reset' : 'save'} failed: ${describeError(err)}`))
      .finally(() => setSaving(false))
  }

  const onDryRun = () => {
    setDry({ loading: true })
    void dryRunExport()
      .then(data => {
        if (data.error) setDry({ loading: false, error: data.error })
        else setDry({ loading: false, data })
      })
      .catch(err => setDry({ loading: false, error: describeError(err) }))
  }

  const combineRunning = !!state.combine_running || runConflict
  const runDisabled = backend !== 'ready' || configInvalid || running || combineRunning

  const onRun = () => {
    setRunning(true)
    setRun({})
    void runExport()
      .then(data => {
        if (data.error) {
          setRun({ error: data.error })
          toast.error(data.error)
          return
        }
        setRun({ data })
        setRunConflict(false)
        if (data.by_kind || data.counts) setState(s => ({ ...s, last_dir: data.dir ?? s.last_dir }))
        toast.success('export written to storage/publish')
      })
      .catch(err => {
        if (apiStatus(err) === 409) {
          setRunConflict(true)
          setState(s => ({ ...s, combine_running: true }))
          setRun({ error: 'A combine run is in progress — export is locked until it finishes (409).' })
          toast.error('export locked: combine running')
        } else {
          setRun({ error: describeError(err) })
          toast.error('export failed')
        }
      })
      .finally(() => setRunning(false))
  }

  const previewText = JSON.stringify(preview.data?.entry ?? preview.data?.json ?? preview.data ?? {}, null, 2)
  const copyText = (text: string, label = 'copied') => {
    void navigator.clipboard?.writeText(text).then(() => toast.success(label)).catch(() => toast.error('copy failed'))
  }

  const copyPublishCmd = () => copyText(PUBLISH_CMD, 'command copied — run it in the CLI')

  const byKind = dry.data?.by_kind ?? dry.data?.counts ?? {}
  const samples = dry.data?.samples ?? dry.data?.sample_files ?? []
  const size = dry.data?.bytes ?? dry.data?.size
  const runByKind = run.data?.by_kind ?? run.data?.counts ?? {}
  const deltas = run.data?.changelog ?? run.data?.deltas

  return (
    <TooltipProvider delay={300}>
      <div className="space-y-4">
        <PageHead
          title="Publish"
          desc="Shape the relational dump, preview an entry, dry-run, then write it to storage/publish."
          actions={backend === 'ready'
            ? <Badge variant="secondary"><Check className="mr-1 h-3 w-3" />backend ready</Badge>
            : undefined}
        />

        {backend === 'missing' && (
          <BackendNotice
            title="Export & Publish backend not ready"
            message={backendError || 'The export API did not respond.'}
            onRetry={retryConfig}
          />
        )}

        {/* Dump layout explainer */}
        <Section title="Dump layout" icon={PackageOpen}>
          <div className="space-y-3 text-xs text-muted-foreground">
            <p className="text-sm leading-relaxed text-foreground">
              Exports land in <code className="rounded bg-muted px-1">storage/publish</code> as a normalized tree: one file per entity under{' '}
              <code className="rounded bg-muted px-1">data/&lt;kind&gt;/&lt;hiyori_id&gt;-&lt;slug&gt;.json</code> (kind is <code className="rounded bg-muted px-1">anime</code> or{' '}
              <code className="rounded bg-muted px-1">manga</code>), plus a <code className="rounded bg-muted px-1">manifest.json</code> index of every file, a{' '}
              <code className="rounded bg-muted px-1">lookup</code> table mapping external ids → Hiyori IDs, the normalized tables (titles, references), and a{' '}
              <code className="rounded bg-muted px-1">CHANGELOG</code> recording added / changed / removed entries between runs.
            </p>
            <p className="flex items-start gap-2 rounded-lg border p-3">
              <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <span>
                <span className="font-medium text-foreground">Hiyori ID rules:</span> ids come from a single global sequence, are unique across anime and manga,
                and <span className="font-medium text-foreground">never shift</span> — once an entity has an id it keeps it forever, so consumers can treat{' '}
                <code className="rounded bg-muted px-1">{'{hiyori_id}'}</code> as a stable key.
              </span>
            </p>
          </div>
        </Section>

        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {/* Config form */}
          <Section title="Config" icon={SlidersHorizontal}>
            <div className="space-y-4">
              {backend === 'checking' ? <Skeleton className="h-24 w-full" /> : (
                <>
                  <div>
                    <label className="mb-1 block text-sm">Profile</label>
                    <Select value={profile} onValueChange={v => setCfg(c => ({ ...c, profile: (v as ExportProfile) ?? 'per-entry-json' }))}>
                      <SelectTrigger className="w-full" aria-label="Export profile"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {EXPORT_PROFILES.map(p => <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>)}
                      </SelectContent>
                    </Select>
                    <p className="mt-1 text-[10px] text-muted-foreground">{EXPORT_PROFILES.find(p => p.value === profile)?.hint}</p>
                  </div>

                  <div>
                    <div className="mb-1 text-sm">Entities</div>
                    <div className="grid grid-cols-2 gap-2">
                      <label className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <Switch aria-label="Include anime entities" checked={entityOn('anime')} onCheckedChange={v => setEntity('anime', v)} />
                        <span>Anime</span>
                      </label>
                      <label className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <Switch aria-label="Include manga entities" checked={entityOn('manga')} onCheckedChange={v => setEntity('manga', v)} />
                        <span>Manga</span>
                      </label>
                    </div>
                    {noEntities && <p className="mt-1 text-[10px] text-warn">Select at least one entity kind.</p>}
                  </div>

                  <div>
                    <label htmlFor="publish-filename" className="mb-1 block text-sm">Filename pattern</label>
                    <Input
                      id="publish-filename"
                      value={pattern}
                      onChange={e => setCfg(c => ({ ...c, filename_pattern: e.target.value }))}
                      placeholder="{hiyori_id}-{slug}"
                      className={cn('h-8 text-xs', patternInvalid && 'border-destructive')}
                      aria-invalid={patternInvalid}
                    />
                    <p className={cn('mt-1 text-[10px]', patternInvalid ? 'text-destructive' : 'text-muted-foreground')}>
                      {patternInvalid
                        ? 'Pattern must contain {hiyori_id}.'
                        : 'Default {hiyori_id}-{slug}; tokens: {hiyori_id}, {slug}.'}
                    </p>
                  </div>

                  <div>
                    <div className="mb-1 text-sm">Fields</div>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                      {FIELD_ROWS.map(f => (
                        <label key={f.key} className={cn('flex items-start gap-2 rounded-lg border px-3 py-2 text-sm', f.v2 && 'opacity-60')}>
                          <Checkbox className="mt-0.5" checked={fieldOn(f.key)} disabled={f.v2} onCheckedChange={v => setField(f.key, v)} />
                          <span className="min-w-0">
                            <span className="flex flex-wrap items-center gap-1.5">
                              {f.label}
                              {f.v2 && <Badge variant="outline" className="text-[10px]">schema v2 — not populated yet</Badge>}
                            </span>
                            <span className="block text-[10px] text-muted-foreground">{f.desc}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                  </div>

                  <div>
                    <div className="mb-1 text-sm">Include</div>
                    <div className="grid grid-cols-3 gap-2">
                      <label className="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs">
                        <Switch aria-label="Write index file" checked={cfg.index ?? true} onCheckedChange={v => setCfg(c => ({ ...c, index: v }))} /><span>Index</span>
                      </label>
                      <label className="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs">
                        <Switch aria-label="Pretty print" checked={cfg.pretty ?? true} onCheckedChange={v => setCfg(c => ({ ...c, pretty: v }))} /><span>Pretty</span>
                      </label>
                      <label className="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs">
                        <Switch aria-label="Bundle output" checked={cfg.bundle ?? false} onCheckedChange={v => setCfg(c => ({ ...c, bundle: v }))} /><span>Bundle</span>
                      </label>
                    </div>
                  </div>

                  <div>
                    <label htmlFor="publish-subdir" className="mb-1 block text-sm">Sub-directory</label>
                    <Input
                      id="publish-subdir"
                      value={cfg.subdir ?? ''}
                      onChange={e => setCfg(c => ({ ...c, subdir: e.target.value }))}
                      placeholder="(published at the publish root)"
                      className="h-8 text-xs"
                    />
                  </div>

                  <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                    <Button size="sm" disabled={saving || configInvalid} onClick={() => persist({ config: cfg }, 'export config saved')}>
                      {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Save className="mr-1.5 h-3.5 w-3.5" />}Save config
                    </Button>
                    <Button size="sm" variant="outline" disabled={saving} onClick={() => persist({ reset: true }, 'config reset to template')}>
                      <RotateCcw className="mr-1.5 h-3.5 w-3.5" />Reset
                    </Button>
                    {template && <span className="text-[10px] text-muted-foreground">template: {String(template.profile ?? 'default')}</span>}
                  </div>
                </>
              )}
            </div>
          </Section>

          {/* Live preview */}
          <Section
            title="Live preview"
            icon={FileJson}
            badge={
              <div className="flex gap-1">
                {(['anime', 'manga'] as ExportKind[]).map(k => (
                  <Button key={k} size="xs" variant={previewKind === k ? 'default' : 'outline'} onClick={() => setPreviewKind(k)}>{k}</Button>
                ))}
              </div>
            }
          >
            <div className="space-y-2">
              {backend === 'missing' && <p className="text-xs text-muted-foreground">Preview unavailable — the export API is not up yet.</p>}
              {preview.loading && <Skeleton className="h-48 w-full" />}
              {!preview.loading && preview.error && (
                <p className="rounded-lg border border-warn/40 bg-warn/5 p-3 text-xs text-muted-foreground">{preview.error}</p>
              )}
              {!preview.loading && !preview.error && (
                <>
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] text-muted-foreground">GET /api/export/preview?kind={previewKind}&amp;profile={profile}</span>
                    <Button size="icon-xs" variant="ghost" className="ml-auto" aria-label="Copy preview JSON" onClick={() => copyText(previewText, 'preview copied')}>
                      <Copy className="h-3 w-3" />
                    </Button>
                    <Button size="icon-xs" variant="ghost" aria-label="Refresh preview" onClick={() => loadPreview(previewKind, profile)}>
                      <RotateCcw className="h-3 w-3" />
                    </Button>
                  </div>
                  <pre className="max-h-80 overflow-auto rounded-lg border bg-muted/40 p-3 text-[11px] leading-relaxed">
                    <code>{previewText}</code>
                  </pre>
                </>
              )}
            </div>
          </Section>
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {/* Dry run */}
          <Section title="Dry run" icon={FolderTree}>
            <div className="space-y-3">
              <p className="text-xs text-muted-foreground">Counts files and estimates size without writing anything.</p>
              <Button size="sm" variant="outline" disabled={backend !== 'ready' || dry.loading || configInvalid} onClick={onDryRun}>
                {dry.loading ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <FolderTree className="mr-1.5 h-3.5 w-3.5" />}Dry run
              </Button>
              {dry.error && <p className="rounded-lg border border-warn/40 bg-warn/5 p-3 text-xs text-muted-foreground">{dry.error}</p>}
              {dry.data && (
                <div className="space-y-2 text-xs">
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <Stat label="Files" value={fmtCompact(dry.data.files)} />
                    <Stat label="Size est." value={size != null ? formatBytes(size) : '—'} />
                    <Stat label="Kinds" value={String(Object.keys(byKind).length)} />
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {Object.entries(byKind).map(([k, v]) => <Badge key={k} variant="outline">{k}: {v}</Badge>)}
                  </div>
                  {samples.length > 0 && (
                    <div>
                      <div className="mb-1 text-[10px] uppercase tracking-wide text-muted-foreground">Sample filenames</div>
                      <ul className="max-h-40 space-y-0.5 overflow-auto rounded-lg border bg-muted/40 p-2 font-mono text-[10px]">
                        {samples.slice(0, 40).map(s => <li key={s} className="truncate">{s}</li>)}
                      </ul>
                    </div>
                  )}
                </div>
              )}
            </div>
          </Section>

          {/* Run export */}
          <Section title="Run export" icon={Play}>
            <div className="space-y-3">
              <p className="text-xs text-muted-foreground">Writes the dump to <code className="rounded bg-muted px-1">storage/publish</code> only — never pushes to HiyoriDB.</p>
              <div className="flex flex-wrap items-center gap-2">
                <Tooltip>
                  <TooltipTrigger render={<span className="inline-flex" />}>
                    <Button size="sm" disabled={runDisabled} onClick={onRun}>
                      {running ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Play className="mr-1.5 h-3.5 w-3.5" />}Run export
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    {combineRunning
                      ? 'A combine run is in progress — export is locked (409).'
                      : configInvalid
                        ? 'Fix the filename pattern / entity selection first.'
                        : 'Writes to storage/publish only; publishing stays CLI-only.'}
                  </TooltipContent>
                </Tooltip>
                {combineRunning && <Badge variant="destructive">combine running — locked</Badge>}
                {state.export_running && <Badge variant="outline">export running</Badge>}
              </div>
              {run.error && <p className="rounded-lg border border-warn/40 bg-warn/5 p-3 text-xs text-muted-foreground">{run.error}</p>}
              {run.data && (
                <div className="space-y-2 text-xs">
                  {run.data.dir && (
                    <div className="flex items-center gap-2">
                      <span className="text-muted-foreground">Dir</span>
                      <code className="truncate rounded bg-muted px-1">{run.data.dir}</code>
                      <Button size="icon-xs" variant="ghost" className="ml-auto" aria-label="Copy directory" onClick={() => copyText(run.data?.dir ?? '', 'directory copied')}>
                        <Copy className="h-3 w-3" />
                      </Button>
                    </div>
                  )}
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <Stat label="Files" value={fmtCompact(run.data.files)} />
                    <Stat label="Size" value={run.data.bytes != null ? formatBytes(run.data.bytes) : '—'} />
                    <Stat label="Deltas" value={deltas ? `+${deltas.added ?? 0} / ~${deltas.changed ?? 0} / -${deltas.removed ?? 0}` : '—'} />
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {Object.entries(runByKind).map(([k, v]) => <Badge key={k} variant="outline">{k}: {v}</Badge>)}
                  </div>
                  {deltas && (
                    <div className="flex flex-wrap gap-2 text-[10px] text-muted-foreground">
                      <span>CHANGELOG: <span className="text-emerald-500">+{deltas.added ?? 0} added</span></span>
                      <span className="text-warn">~{deltas.changed ?? 0} changed</span>
                      <span className="text-red-500">-{deltas.removed ?? 0} removed</span>
                    </div>
                  )}
                </div>
              )}
            </div>
          </Section>
        </div>

        {/* Guarded publish — ships OFF */}
        <Section title="Publish to HiyoriDB" icon={Upload} badge={<Badge variant="outline">ships OFF</Badge>}>
          <div className="space-y-3 text-xs">
            <div className="flex items-start gap-2 rounded-lg border border-warn/40 bg-warn/5 p-3">
              <ShieldAlert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warn" />
              <p className="text-muted-foreground">
                Pushing is intentionally disabled in the dashboard. Publishing clones the HiyoriDB repository, commits and pushes — that stays CLI-only so a
                mis-click can never rewrite the public repo. The export above only writes to local storage.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" variant="outline" onClick={() => setPublishOpen(true)}>
                <Upload className="mr-1.5 h-3.5 w-3.5" />Publish…
              </Button>
              <Button size="sm" variant="ghost" onClick={copyPublishCmd}>
                <Copy className="mr-1.5 h-3.5 w-3.5" />Copy CLI command
              </Button>
              <code className="rounded bg-muted px-2 py-1">{PUBLISH_CMD}</code>
            </div>
          </div>
        </Section>
      </div>

      <Dialog open={publishOpen} onOpenChange={setPublishOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Run the publish command</DialogTitle>
            <DialogDescription>
              The dashboard never pushes anything — it cannot. Publishing runs
              from your terminal, so this only copies the command.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <code className="block overflow-x-auto rounded bg-muted px-2 py-1.5 text-xs">{PUBLISH_CMD}</code>
            <p className="text-xs text-muted-foreground">
              It pushes <code className="rounded bg-muted px-1">storage/publish</code> to HiyoriDB and needs
              <code className="mx-1 rounded bg-muted px-1">HIYORI_DB_TOKEN</code> set in your environment.
            </p>
          </div>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setPublishOpen(false)}>Close</Button>
            <Button size="sm" onClick={() => { copyPublishCmd(); setPublishOpen(false) }}>
              <Terminal className="mr-1.5 h-3.5 w-3.5" />Copy command
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </TooltipProvider>
  )
}

/** Small labelled metric used by the dry-run / run summaries. */
function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border px-3 py-2">
      <div className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</div>
      <div className="text-sm font-medium tabular-nums">{value}</div>
    </div>
  )
}
