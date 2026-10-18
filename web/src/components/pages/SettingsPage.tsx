import { useEffect, useState, type ReactNode } from 'react'
import { Check, Clock, Copy, Database, Download, Gauge, KeyRound, LayoutDashboard, Moon, PackageOpen, Palette, RotateCcw, Rows3, Send, Settings2, Sun, X } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from 'cn'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import {
  EXPORT_PROFILES, fetchExportConfig, fetchLimits, fetchMeta, fetchSettings, postLimits, postSettings, saveExportConfig,
  type ExportConfig, type UiSettings,
} from '@/lib/api'
import { useDebouncedCallback, useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { fmtCompact } from '@/lib/format'
import { Code } from '../code'
import { EmptyState } from '../empty'
import { Notice } from '../notice'
import { OptionTile } from '../option-tile'
import { hiddenWidgets, toggleWidgetId, WIDGETS } from '@/lib/widgets'
import { PageHead, Section } from '../widgets'
import { EnvSettings } from './settings-env'

export { WIDGETS } from '@/lib/widgets'

/** Compact choice chip / swatch used across the Interface card (S1). */
function Chip({ active, onClick, children }: { active?: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition',
        active ? 'border-transparent bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted',
      )}
    >
      {children}
    </button>
  )
}

export function SettingsPage() {
  const { settings, update, refresh } = useSettings()
  const [env, setEnv] = useState<{ token_present?: boolean; webhook?: string }>({})
  const [meta, setMeta] = useState<Record<string, { count?: number }>>({})
  const [webhook, setWebhook] = useState('')
  const [newToken, setNewToken] = useState<string | null>(null)
  const [exportConfig, setExportConfig] = useState<ExportConfig | null>(null)
  const [exportApi, setExportApi] = useState<'checking' | 'ready' | 'missing'>('checking')

  useEffect(() => { void fetchSettings().then(r => { setEnv(r.env ?? {}); setWebhook(r.env?.webhook ?? '') }) }, [])
  useEffect(() => { void fetchMeta().then(m => setMeta(m.collections ?? {})) }, [])
  // Shares the export config with the Publish page (GET/POST /api/export-config).
  useEffect(() => {
    void fetchExportConfig()
      .then(r => { setExportConfig(r.config ?? r.template ?? {}); setExportApi('ready') })
      .catch(() => setExportApi('missing'))
  }, [])

  const prefs = settings.prefs ?? {}
  const [refreshLocal, setRefreshLocal] = useState<number>(prefs.refresh ?? 3000)
  const setPre = (k: string, v: unknown) => void update({ prefs: { ...prefs, [k]: v } })
  const persistRefresh = useDebouncedCallback((v: number) => setPre('refresh', v), 400)
  const hiddenWidgetIds = hiddenWidgets(prefs)
  const dark = prefs.theme !== 'light'
  const density = prefs.density ?? 'compact'
  const tz = prefs.tz ?? 'local'
  const realtime = prefs.realtime !== false
  const hidden = prefs.hiddenCollections ?? []

  // Rate limiter (moved from the removed Ingest page): live circuit state comes
  // from status health; the overrides are persisted server-side via /api/limits.
  const { data: status } = useStatus()
  const circuit = Object.entries(status?.health?.checks?.rate_limiter?.limiters ?? {})
  const [limits, setLimits] = useState<{ max?: number; window?: number; source?: string }>({})
  const [max, setMax] = useState(100)
  const [win, setWin] = useState(60)
  useEffect(() => {
    void fetchLimits()
      .then(r => {
        setLimits(r.limits ?? {})
        setMax(r.limits?.max ?? 100)
        setWin(r.limits?.window ?? 60)
      })
      .catch(() => { /* keep defaults until the endpoint answers */ })
  }, [])
  const saveLimits = async () => {
    try {
      await postLimits({ max, window: win })
      toast.success('limits saved — applied on next ingest start')
      const r = await fetchLimits()
      setLimits(r.limits ?? {})
    } catch (e) {
      toast.error(`limits not saved — ${e instanceof Error ? e.message : String(e)}`)
    }
  }

  const setPref = (patch: object) => void update({ prefs: { ...prefs, ...patch } })

  useEffect(() => {
    document.documentElement.classList.toggle('density-compact', density === 'compact')
  }, [density])

  const toggleTheme = () => {
    const next = dark ? 'light' : 'dark'
    document.documentElement.classList.toggle('dark', next === 'dark')
    setPref({ theme: next })
  }
  const toggleWidget = (id: string) => {
    setPref({ hiddenWidgets: toggleWidgetId(prefs, id) })
  }
  const toggleCol = (c: string) => {
    setPref({ hiddenCollections: hidden.includes(c) ? hidden.filter(x => x !== c) : [...hidden, c] })
  }

  // postSettings rejects on a non-OK response, so every caller needs a catch —
  // otherwise a failure is an unhandled rejection with no visible feedback.
  const call = async (body: object, ok: string) => {
    try {
      const r = await postSettings(body)
      toast.success(ok)
      return r
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'request failed')
      return null
    }
  }

  const rotate = async () => {
    const r = await call({ token_rotate: true }, 'token rotated — re-login needed')
    setNewToken((r?.token as string | undefined) ?? null)
  }
  const saveWebhook = async () => {
    const r = await call({ webhook }, 'webhook saved')
    if (r) setEnv(e => ({ ...e, webhook: (r.webhook as string | undefined) ?? webhook }))
  }
  const testWebhook = async () => {
    try {
      const r = await postSettings({ webhook_test: true }) as { test?: string }
      if (r.test === 'sent') toast.success('test delivered')
      else toast.error(r.test ?? 'failed')
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'webhook test failed')
    }
  }
  const exportJson = () => {
    const blob = new Blob([JSON.stringify(settings, null, 2)], { type: 'application/json' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = 'hiyori-dashboard-settings.json'
    a.click()
  }
  // S3: import straight from a picked .json file (no paste blob).
  const importJson = async (file: File) => {
    try {
      const parsed = JSON.parse(await file.text()) as UiSettings
      await update(parsed)
      toast.success('settings imported')
      refresh()
    } catch {
      toast.error('invalid JSON')
    }
  }
  const resetAll = async () => {
    if (!await call({ reset: true }, 'settings reset')) return
    refresh()
  }

  // Export & Publish: mirror the dump profile and jump to the Publish page.
  const exportProfile = typeof exportConfig?.profile === 'string' && exportConfig.profile ? exportConfig.profile : 'per-entry-json'
  const saveExportProfile = (v: string) => {
    const next: ExportConfig = { ...(exportConfig ?? {}), profile: v }
    setExportConfig(next)
    void saveExportConfig({ config: next })
      .then(() => { setExportApi('ready'); toast.success('export profile saved') })
      .catch(() => toast.error('export profile not saved — backend not ready'))
  }

  return (
    <div className="space-y-4">
      <PageHead title="Settings" desc="Dashboard preferences — persisted to the server and applied immediately." />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Live dashboard" icon={Sun}>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm">Real-time updates</div>
                <div className="text-xs text-muted-foreground">SSE push over the base poll; off = polling only</div>
              </div>
              <Switch aria-label="Realtime updates" checked={realtime} onCheckedChange={(v: boolean) => setPre('realtime', v)} />
            </div>
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm">Refresh interval</div>
                <div className="text-xs text-muted-foreground">base polling rate; adaptively slows when idle</div>
              </div>
              <div className="flex w-56 items-center gap-3">
                <Slider
                  value={[refreshLocal]}
                  min={1000}
                  max={60000}
                  step={500}
                  aria-label="Refresh interval in seconds"
                  onValueChange={(val) => { const v = Array.isArray(val) ? val[0] : val; const n = Number(v ?? 3000); setRefreshLocal(n); persistRefresh(n) }}
                  className="flex-1"
                />
                <span className="w-14 text-right text-sm tabular-nums">{(refreshLocal / 1000).toFixed(1)}s</span>
              </div>
            </div>
          </div>
        </Section>

        {/* S1: theme + density + timezone as one compact Interface card */}
        <Section title="Interface" icon={Palette}>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm">Theme</span>
              <div className="flex gap-1.5">
                <Chip active={dark} onClick={() => { if (!dark) toggleTheme() }}><Moon className="h-3.5 w-3.5" />Dark</Chip>
                <Chip active={!dark} onClick={() => { if (dark) toggleTheme() }}><Sun className="h-3.5 w-3.5" />Light</Chip>
              </div>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm">Density</span>
              <div className="flex gap-1.5">
                <Chip active={density === 'compact'} onClick={() => setPref({ density: 'compact' })}><Rows3 className="h-3.5 w-3.5" />Compact</Chip>
                <Chip active={density !== 'compact'} onClick={() => setPref({ density: 'comfortable' })}><Rows3 className="h-3.5 w-3.5" />Comfortable</Chip>
              </div>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm">Timezone</span>
              <div className="flex gap-1.5">
                <Chip active={tz === 'local'} onClick={() => setPref({ tz: 'local' })}><Clock className="h-3.5 w-3.5" />Local</Chip>
                <Chip active={tz !== 'local'} onClick={() => setPref({ tz: 'utc' })}><Clock className="h-3.5 w-3.5" />UTC</Chip>
              </div>
            </div>
          </div>
        </Section>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Dashboard widgets" icon={LayoutDashboard}>
          <p className="mb-2 text-xs text-muted-foreground">Choose which widgets appear on the Dashboard page.</p>
          <div className="grid grid-cols-2 gap-2">
            {WIDGETS.map(([id, label]) => (
              <OptionTile
                key={id}
                control={<Switch aria-label={`Toggle ${id} widget`} checked={!hiddenWidgetIds.includes(id)} onCheckedChange={() => toggleWidget(id)} />}
              >
                {label}
              </OptionTile>
            ))}
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Unchecking a widget hides it; check to restore.</p>
        </Section>

        <Section title="Collections" icon={Database}>
          <p className="mb-2 text-xs text-muted-foreground">Hide collections from the Dataset view (they stay in Explorer).</p>
          <div className="grid grid-cols-2 gap-2">
            {Object.keys(meta).map(c => (
              <OptionTile
                key={c}
                control={<Switch aria-label={`Show ${c} collection`} checked={!hidden.includes(c)} onCheckedChange={() => toggleCol(c)} />}
              >
                {c} <span className="text-xs text-muted-foreground">({fmtCompact(meta[c]?.count)})</span>
              </OptionTile>
            ))}
          </div>
        </Section>
      </div>

      <Section title="Rate limiter" icon={Gauge}>
        <div className="space-y-3 text-sm">
          {circuit.length === 0 && <p className="text-xs text-muted-foreground">No limiter state reported yet (needs an ingestion run).</p>}
          {circuit.map(([src, s]) => (
            <div key={src} className="flex items-center gap-2 rounded-lg border px-3 py-2">
              <Badge variant={s.circuit_state === 'open' ? 'destructive' : 'secondary'}>{s.circuit_state === 'open' ? 'OPEN' : 'closed'}</Badge>
              <span className="flex-1 text-xs">{src}</span>
              <span className="text-right text-xs tabular-nums text-muted-foreground">{s.tokens_available ?? 0}/{s.max_tokens ?? 0} tokens</span>
            </div>
          ))}
          <div className="rounded-lg border p-3">
            <p className="text-xs font-medium text-muted-foreground">Overrides</p>
            <p className="mb-2 text-[10px] text-muted-foreground">Applied to ingest processes on start.</p>
            <div className="flex flex-wrap items-end gap-2">
              <div className="flex flex-col gap-1">
                <label htmlFor="rate-max" className="text-[10px] text-muted-foreground">Max requests</label>
                <Input id="rate-max" type="number" value={max} onChange={e => setMax(Number(e.target.value))} className="h-8 w-24 text-xs" />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="rate-window" className="text-[10px] text-muted-foreground">Window (s)</label>
                <Input id="rate-window" type="number" value={win} onChange={e => setWin(Number(e.target.value))} className="h-8 w-24 text-xs" />
              </div>
              <Button size="sm" variant="outline" onClick={() => void saveLimits()}>Save</Button>
            </div>
            <p className="mt-2 text-[10px] text-muted-foreground">current: {limits.max ?? '—'} req / {limits.window ?? '—'}s{limits.source ? ` · ${limits.source}` : ''}</p>
          </div>
        </div>
      </Section>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Access & integration" icon={KeyRound}>
          <div className="space-y-3">
            <div className="flex items-center justify-between rounded-lg border px-3 py-2">
              <div>
                <div className="text-sm">Dashboard token</div>
                <div className="text-xs text-muted-foreground">{env.token_present ? 'a token is protecting the API' : 'no token set — API open'}</div>
              </div>
              <Button size="sm" variant="outline" onClick={() => void rotate()}><RotateCcw className="mr-1.5" />Rotate</Button>
            </div>
            {newToken && (
              <Notice
                tone="success"
                title={
                  <span className="flex items-center gap-2">
                    New token (copy now — shown once)
                    <Button size="icon-xs" variant="ghost" aria-label="Copy new token" onClick={() => { void navigator.clipboard.writeText(newToken); toast('copied') }}><Copy /></Button>
                  </span>
                }
              >
                <Code variant="plain" className="break-all">{newToken}</Code>
              </Notice>
            )}
            <div className="rounded-lg border p-3">
              <div className="mb-2 text-sm">Webhook URL</div>
              <div className="flex gap-2">
                <Input value={webhook} onChange={e => setWebhook(e.target.value)} placeholder="https://hooks.slack.com/services/…" className="h-8 text-xs" />
                <Button size="sm" variant="outline" onClick={() => void saveWebhook()}><Check className="mr-1" />Save</Button>
                <Button size="sm" variant="outline" onClick={() => void testWebhook()}><Send className="mr-1" />Test</Button>
              </div>
              <p className="mt-1 text-[10px] text-muted-foreground">Delivers alert messages (throttled to 1/hour per alert type).</p>
            </div>
          </div>
        </Section>

        <Section title="Import / export" icon={Download}>
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <Button size="sm" variant="outline" onClick={exportJson}><Download className="mr-1.5" />Export settings JSON</Button>
              <Button size="sm" variant="destructive" onClick={() => { if (window.confirm('Reset all dashboard preferences and audit trail?')) void resetAll() }}>Reset telemetry & prefs</Button>
            </div>
            <div>
              <div className="mb-1.5 text-sm">Import</div>
              <Input
                type="file"
                accept="application/json,.json"
                aria-label="Import settings JSON"
                className="h-9 w-full max-w-md text-xs"
                onChange={e => {
                  const f = e.target.files?.[0]
                  if (f) void importJson(f)
                  e.target.value = ''
                }}
              />
              <p className="mt-1 text-xs text-muted-foreground">Pick a settings JSON file (from Export) to restore prefs on this dashboard.</p>
            </div>
            {Object.keys(settings).length === 0 && (
              <EmptyState icon={X} title="No custom settings yet" description="Preferences you change here persist across page loads and browsers." />
            )}
          </div>
        </Section>
      </div>

      {/* Export & Publish shortcut — mirrors the Publish page's profile selector. */}
      <Section
        title="Export & Publish"
        icon={PackageOpen}
        badge={exportApi === 'missing' ? <Badge variant="outline">backend not ready</Badge> : undefined}
      >
        <div className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <div className="text-sm">Dump profile</div>
              <div className="text-xs text-muted-foreground">Used by <Code>php hiyori export</Code> (GET/POST /api/export-config).</div>
            </div>
            <Select value={exportProfile} onValueChange={v => saveExportProfile(v ?? 'per-entry-json')}>
              <SelectTrigger className="h-8 w-44" aria-label="Dump profile"><SelectValue /></SelectTrigger>
              <SelectContent>
                {EXPORT_PROFILES.map(p => <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {exportApi === 'missing' && <span className="text-[10px] text-muted-foreground">Export API not reachable — the profile is kept locally.</span>}
          </div>
        </div>
      </Section>

      {/* S2: Environment manager under a collapsible Advanced section */}
      <Section title="Advanced" icon={Settings2} defaultOpen={false}>
        <EnvSettings />
      </Section>
    </div>
  )
}
