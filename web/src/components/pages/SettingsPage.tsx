import { useEffect, useState, type ReactNode } from 'react'
import { Check, Clock, Copy, Database, Download, KeyRound, LayoutDashboard, Moon, PackageOpen, Palette, RotateCcw, Rows3, Send, Settings2, Sun, X } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from 'cn'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import {
  EXPORT_PROFILES, fetchExportConfig, fetchMeta, fetchSettings, postSettings, saveExportConfig,
  type ExportConfig, type UiSettings,
} from '@/lib/api'
import { useDebouncedCallback, useSettings } from '@/lib/hooks'
import { fmtCompact } from '@/lib/format'
import { EmptyState } from '../empty'
import { Notice } from '../notice'
import { PageHead, Section } from '../widgets'
import { EnvSettings } from './settings-env'

export const WIDGETS: Array<[string, string]> = [
  ['kpi', 'KPI row'],
  ['fleet', 'Sources fleet'],
  ['dataset', 'Dataset donut'],
  ['health', 'Merge confidence'],
]

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
  const widgets = prefs.widgets
  const dark = prefs.theme !== 'light'
  const density = prefs.density ?? 'compact'
  const tz = prefs.tz ?? 'local'
  const realtime = prefs.realtime !== false
  const hidden = prefs.hiddenCollections ?? []

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
    const cur = widgets ?? WIDGETS.map(w => w[0])
    setPref({ widgets: cur.includes(id) ? cur.filter(w => w !== id) : [...cur, id] })
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
              <label key={id} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                <Switch aria-label={`Toggle ${id} widget`} checked={!widgets || widgets.includes(id)} onCheckedChange={() => toggleWidget(id)} />
                <span>{label}</span>
              </label>
            ))}
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Unchecking a widget hides it; check to restore.</p>
        </Section>

        <Section title="Collections" icon={Database}>
          <p className="mb-2 text-xs text-muted-foreground">Hide collections from the Dataset view (they stay in Explorer).</p>
          <div className="grid grid-cols-2 gap-2">
            {Object.keys(meta).map(c => (
              <label key={c} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                <Switch aria-label={`Show ${c} collection`} checked={!hidden.includes(c)} onCheckedChange={() => toggleCol(c)} />
                <span>{c} <span className="text-xs text-muted-foreground">({fmtCompact(meta[c]?.count)})</span></span>
              </label>
            ))}
          </div>
        </Section>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Access & integration" icon={KeyRound}>
          <div className="space-y-3">
            <div className="flex items-center justify-between rounded-lg border px-3 py-2">
              <div>
                <div className="text-sm">Dashboard token</div>
                <div className="text-xs text-muted-foreground">{env.token_present ? 'a token is protecting the API' : 'no token set — API open'}</div>
              </div>
              <Button size="sm" variant="outline" onClick={() => void rotate()}><RotateCcw className="mr-1.5 h-3.5 w-3.5" />Rotate</Button>
            </div>
            {newToken && (
              <Notice
                tone="success"
                title={
                  <span className="flex items-center gap-2">
                    New token (copy now — shown once)
                    <Button size="icon-xs" variant="ghost" aria-label="Copy new token" onClick={() => { void navigator.clipboard.writeText(newToken); toast('copied') }}><Copy className="h-3 w-3" /></Button>
                  </span>
                }
              >
                <code className="break-all">{newToken}</code>
              </Notice>
            )}
            <div className="rounded-lg border p-3">
              <div className="mb-2 text-sm">Webhook URL</div>
              <div className="flex gap-2">
                <Input value={webhook} onChange={e => setWebhook(e.target.value)} placeholder="https://hooks.slack.com/services/…" className="h-8 text-xs" />
                <Button size="sm" variant="outline" onClick={() => void saveWebhook()}><Check className="mr-1 h-3.5 w-3.5" />Save</Button>
                <Button size="sm" variant="outline" onClick={() => void testWebhook()}><Send className="mr-1 h-3.5 w-3.5" />Test</Button>
              </div>
              <p className="mt-1 text-[10px] text-muted-foreground">Delivers alert messages (throttled to 1/hour per alert type).</p>
            </div>
          </div>
        </Section>

        <Section title="Import / export" icon={Download}>
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <Button size="sm" variant="outline" onClick={exportJson}><Download className="mr-1.5 h-3.5 w-3.5" />Export settings JSON</Button>
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
              <div className="text-xs text-muted-foreground">Shared with the Publish page's export config (GET/POST /api/export-config).</div>
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
