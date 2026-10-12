import { useEffect, useState } from 'react'
import { Check, Copy, Download, KeyRound, Moon, RotateCcw, Send, Sun, Upload, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import { fetchMeta, fetchSettings, postSettings, type UiSettings } from '@/lib/api'
import { useDebouncedCallback, useSettings } from '@/lib/hooks'
import { EmptyState } from '../empty'
import { PageHead, Section } from '../widgets'
import { EnvSettings } from './settings-env'

export const WIDGETS: Array<[string, string]> = [
  ['kpi', 'KPI row'],
  ['fleet', 'Sources fleet'],
  ['rates', 'Ingestion rate'],
  ['counts', 'Collection share'],
  ['dataset', 'Dataset donut'],
  ['health', 'Health gauges'],
  ['pipeline', 'Pipeline funnel'],
  ['alerts', 'Alert strip'],
]
const ACCENTS = ['#4fb3ff', '#3fd06a', '#e8b64c', '#9b7bff', '#ff5f57', '#ff8fd8']

export function SettingsPage() {
  const { settings, update, refresh } = useSettings()
  const [env, setEnv] = useState<{ token_present?: boolean; webhook?: string }>({})
  const [meta, setMeta] = useState<Record<string, { count?: number }>>({})
  const [webhook, setWebhook] = useState('')
  const [newToken, setNewToken] = useState<string | null>(null)
  const [importText, setImportText] = useState('')

  useEffect(() => { void fetchSettings().then(r => { setEnv(r.env ?? {}); setWebhook(r.env?.webhook ?? '') }) }, [])
  useEffect(() => { void fetchMeta().then(m => setMeta(m.collections ?? {})) }, [])

  const prefs = settings.prefs ?? {}
  const [refreshLocal, setRefreshLocal] = useState<number>(prefs.refresh ?? 3000)
  const setPre = (k: string, v: unknown) => void update({ prefs: { ...prefs, [k]: v } })
  const persistRefresh = useDebouncedCallback((v: number) => setPre('refresh', v), 400)
  const widgets = prefs.widgets
  const dark = prefs.theme !== 'light'
  const density = prefs.density ?? 'comfortable'
  const accent = prefs.accent ?? '#4fb3ff'
  const tz = prefs.tz ?? 'local'
  const hidden = prefs.hiddenCollections ?? []

  const setPref = (patch: object) => void update({ prefs: { ...prefs, ...patch } })

  useEffect(() => {
    document.documentElement.style.setProperty('--accent-ui', accent)
    document.documentElement.classList.toggle('density-compact', density === 'compact')
  }, [accent, density])

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

  const rotate = async () => {
    const r = await postSettings({ token_rotate: true })
    setNewToken(r.token ?? null)
    toast.success('token rotated — re-login needed')
  }
  const saveWebhook = async () => {
    const r = await postSettings({ webhook })
    setEnv(e => ({ ...e, webhook: r.webhook ?? webhook }))
    toast.success('webhook saved')
  }
  const testWebhook = async () => {
    const r = await postSettings({ webhook_test: true }) as { test?: string }
    if (r.test === 'sent') toast.success('test delivered')
    else toast.error(r.test ?? 'failed')
  }
  const exportJson = () => {
    const blob = new Blob([JSON.stringify(settings, null, 2)], { type: 'application/json' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = 'hiyori-dashboard-settings.json'
    a.click()
  }
  const importJson = async () => {
    try {
      const parsed = JSON.parse(importText) as UiSettings
      await update(parsed)
      toast.success('settings imported')
      setImportText('')
      refresh()
    } catch {
      toast.error('invalid JSON')
    }
  }
  const resetAll = async () => {
    await postSettings({ reset: true })
    toast('settings reset')
    refresh()
  }

  return (
    <div className="space-y-4">
      <PageHead title="Settings" desc="Dashboard preferences — persisted to the server and applied immediately." />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Live dashboard" icon={Sun}>
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm">Theme</span>
              <Button size="sm" variant="outline" onClick={toggleTheme}>{dark ? <Sun className="mr-1.5 h-3.5 w-3.5" /> : <Moon className="mr-1.5 h-3.5 w-3.5" />}{dark ? 'Dark' : 'Light'}</Button>
            </div>
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm">Refresh interval</div>
                <div className="text-xs text-muted-foreground">base polling rate; adaptively slows when idle</div>
              </div>
              <div className="flex w-56 items-center gap-3">
                <Slider value={[refreshLocal]} min={1000} max={60000} step={500} onValueChange={(val) => { const v = Array.isArray(val) ? val[0] : val; const n = Number(v ?? 3000); setRefreshLocal(n); persistRefresh(n) }} className="flex-1" />
                <span className="w-14 text-right text-sm tabular-nums">{(refreshLocal / 1000).toFixed(1)}s</span>
              </div>
            </div>
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm">Timezone</div>
                <div className="text-xs text-muted-foreground">how timestamps render</div>
              </div>
              <Button size="sm" variant="outline" onClick={() => tz === 'local' ? setPref({ tz: 'utc' }) : setPref({ tz: 'local' })}>{tz === 'local' ? 'Local' : 'UTC'}</Button>
            </div>
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm">Density</div>
                <div className="text-xs text-muted-foreground">table & row spacing</div>
              </div>
              <Button size="sm" variant="outline" onClick={() => setPref({ density: density === 'comfortable' ? 'compact' : 'comfortable' })}>{density}</Button>
            </div>
            <div>
              <div className="mb-2 text-sm">Accent</div>
              <div className="flex gap-2">
                {ACCENTS.map(a => (
                  <button key={a} onClick={() => setPref({ accent: a })} className={`flex h-8 w-8 items-center justify-center rounded-full transition ${accent === a ? 'ring-2 ring-foreground' : ''}`} style={{ background: a }}>
                    {accent === a && <Check className="h-4 w-4 text-white" />}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </Section>

        <Section title="Dashboard widgets" icon={X}>
          <p className="mb-2 text-xs text-muted-foreground">Choose which widgets appear on the Dashboard page.</p>
          <div className="grid grid-cols-2 gap-2">
            {WIDGETS.map(([id, label]) => (
              <label key={id} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                <Switch checked={!widgets || widgets.includes(id)} onCheckedChange={() => toggleWidget(id)} />
                <span>{label}</span>
              </label>
            ))}
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Unchecking a widget hides it; check to restore.</p>
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
              <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-xs">
                <div className="mb-1 flex items-center gap-2 text-emerald-400">New token (copy now — shown once)
                  <Button size="icon-xs" variant="ghost" aria-label="Copy new token" onClick={() => { void navigator.clipboard.writeText(newToken); toast('copied') }}><Copy className="h-3 w-3" /></Button>
                </div>
                <code className="break-all">{newToken}</code>
              </div>
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

        <Section title="Collections" icon={Upload}>
          <p className="mb-2 text-xs text-muted-foreground">Hide collections from the Dataset view (they stay in Explorer).</p>
          <div className="grid grid-cols-2 gap-2">
            {Object.keys(meta).map(c => (
              <label key={c} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                <Switch checked={!hidden.includes(c)} onCheckedChange={() => toggleCol(c)} />
                <span>{c} <span className="text-xs text-muted-foreground">({meta[c]?.count?.toLocaleString()})</span></span>
              </label>
            ))}
          </div>
        </Section>
      </div>

      <Section title="Import / export" icon={Download}>
        <div className="space-y-3">
          <div className="flex items-center gap-2">
            <Button size="sm" variant="outline" onClick={exportJson}><Download className="mr-1.5 h-3.5 w-3.5" />Export settings JSON</Button>
            <Button size="sm" variant="destructive" onClick={() => { if (window.confirm('Reset all dashboard preferences and audit trail?')) void resetAll() }}>Reset telemetry & prefs</Button>
          </div>
          <div className="flex items-start gap-2">
            <textarea value={importText} onChange={e => setImportText(e.target.value)} placeholder='Paste a settings JSON blob to import…' className="h-20 flex-1 rounded-lg border bg-transparent p-2 text-xs" />
            <Button size="sm" variant="outline" onClick={() => void importJson()}><Upload className="mr-1.5 h-3.5 w-3.5" />Import</Button>
          </div>
          {Object.keys(settings).length === 0 && (
            <EmptyState icon={X} title="No custom settings yet" description="Preferences you change here persist across page loads and browsers." />
          )}
        </div>
      </Section>
      <Section title="Environment" icon={KeyRound}>
        <EnvSettings />
      </Section>
    </div>
  )
}
