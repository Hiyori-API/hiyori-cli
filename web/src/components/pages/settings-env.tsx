import { useCallback, useEffect, useState } from 'react'
import { Download, Eye, EyeOff, FileText, KeyRound, Plus, Save, ShieldQuestion, Trash2, Upload, X } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { fetchEnv, fetchEnvRaw, postEnv, postEnvPatterns, postEnvRaw, type EnvVar } from '@/lib/api'
import { useSettings } from '@/lib/hooks'
import { EmptyState } from '../empty'

/** Settings → Environment: manage .env variables from the dashboard. */
export function EnvSettings() {
  const [env, setEnv] = useState<EnvVar[] | null>(null)
  const [revealed, setRevealed] = useState<Set<string>>(new Set())
  const [editing, setEditing] = useState<string | null>(null)
  const [editVal, setEditVal] = useState('')
  const [newKey, setNewKey] = useState('')
  const [newVal, setNewVal] = useState('')
  const [confirmRevealAll, setConfirmRevealAll] = useState(false)
  const [raw, setRaw] = useState<string | null>(null)
  const [rawOpen, setRawOpen] = useState(false)
  const [pattern, setPattern] = useState('')
  const { settings } = useSettings()
  const patterns = (settings.env_sensitive_patterns as string[] | undefined) ?? []

  const reload = useCallback(async () => {
    setEnv((await fetchEnv()).env ?? [])
  }, [])

  useEffect(() => { void reload() }, [reload])

  const reveal = async (key: string) => {
    if (revealed.has(key)) {
      setRevealed(s => { const n = new Set(s); n.delete(key); return n })
      return
    }
    const res = await fetchEnv(key)
    setEnv(res.env ?? [])
    setRevealed(s => new Set(s).add(key))
  }

  const update = async (key: string, value: string | null) => {
    const r = await postEnv({ key, value })
    if (r.error) { toast.error(r.error); return }
    setEditing(null)
    await reload()
    toast.success(value === null ? `${key} removed` : `${key} updated`)
  }

  const add = async () => {
    const key = newKey.trim()
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) { toast.error('invalid key (letters/digits/underscore)'); return }
    await update(key, newVal)
    setNewKey(''); setNewVal('')
  }

  const revealAll = async () => {
    for (const v of env ?? []) {
      if (!v.sensitive) continue
      const res = await fetchEnv(v.key)
      if (!res.env) continue
      setEnv(prev => (prev ?? []).map(e => (e.key === v.key ? (res.env ?? []).find(x => x.key === v.key) ?? e : e)))
      setRevealed(s => new Set(s).add(v.key))
    }
    setConfirmRevealAll(false)
    toast.success('All secrets revealed')
  }

  const toggleRaw = async (open: boolean) => {
    setRawOpen(open)
    if (open && raw === null) {
      const res = await fetchEnvRaw()
      setRaw(res.raw ?? '')
    }
  }

  const saveRaw = async () => {
    const r = await postEnvRaw(raw ?? '')
    if (r.error) { toast.error(r.error); return }
    setRawOpen(false)
    await reload()
    toast.success('.env updated')
  }

  const downloadRaw = async () => {
    const res = await fetchEnvRaw()
    const blob = new Blob([res.raw ?? ''], { type: 'text/plain' })
    const a = document.createElement('a')
    const url = URL.createObjectURL(blob)
    a.href = url
    a.download = '.env'
    a.click()
    URL.revokeObjectURL(url)
  }

  const addPattern = async () => {
    const p = pattern.trim()
    if (!p) return
    await postEnvPatterns([...patterns, p])
    setPattern('')
    await reload()
    toast.success('pattern added')
  }
  const removePattern = async (p: string) => {
    await postEnvPatterns(patterns.filter(x => x !== p))
    await reload()
  }

  const vars = env ?? []
  const sensitive = vars.filter(v => v.sensitive)
  const config = vars.filter(v => !v.sensitive)

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted-foreground">
        Manage the dashboard’s <code className="rounded bg-muted px-1">.env</code> variables directly. Secrets are masked by default;
        use 👁 to reveal one at a time. Changes apply on the next request. Actions are audited.
      </p>

      {env === null ? <Skeleton className="h-32 w-full" /> : vars.length === 0 ? (
        <EmptyState icon={KeyRound} title="No environment variables" description="Everything the app loads from .env will appear here." />
      ) : (
        <div className="space-y-4">
          <EnvTable rows={sensitive} title="Sensitive" onReveal={reveal}
            editing={editing} setEditing={setEditing} editVal={editVal} setEditVal={setEditVal}
            revealed={revealed} onUpdate={update} />
          <EnvTable rows={config} title="Configuration" onReveal={reveal}
            editing={editing} setEditing={setEditing} editVal={editVal} setEditVal={setEditVal}
            revealed={revealed} onUpdate={update} />
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2 rounded-lg border p-3">
        <div className="flex flex-col gap-1">
          <span className="text-xs text-muted-foreground">KEY</span>
          <Input value={newKey} onChange={e => setNewKey(e.target.value)} placeholder="NEW_SETTING" className="h-8 w-48 font-mono text-xs" />
        </div>
        <div className="flex flex-col gap-1">
          <span className="text-xs text-muted-foreground">VALUE</span>
          <Input value={newVal} onChange={e => setNewVal(e.target.value)} placeholder="value" className="h-8 w-56 font-mono text-xs" />
        </div>
        <Button size="sm" onClick={() => void add()}><Plus className="mr-1.5 h-3.5 w-3.5" />Add variable</Button>
        <div className="ml-auto flex items-center gap-1.5">
          <Button size="sm" variant="outline" onClick={() => setConfirmRevealAll(true)}><Eye className="mr-1.5 h-3.5 w-3.5" />Reveal all</Button>
          <Button size="sm" variant="outline" onClick={() => void toggleRaw(true)}><FileText className="mr-1.5 h-3.5 w-3.5" />Raw file</Button>
          <Button size="sm" variant="outline" onClick={() => void downloadRaw()}><Download className="mr-1.5 h-3.5 w-3.5" />Export</Button>
        </div>
      </div>

      {/* sensitivity rules */}
      <div className="rounded-lg border p-3">
        <div className="mb-2 flex items-center gap-2 text-sm"><ShieldQuestion className="h-4 w-4 text-muted-foreground" />Sensitivity rules
          <span className="text-xs text-muted-foreground">keys matching one of these regexes are masked</span></div>
        <div className="mb-2 flex flex-wrap gap-1.5">
          {patterns.map(p => (
            <span key={p} className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 font-mono text-[11px]">
              {p}
              <button className="text-muted-foreground hover:text-red-400" aria-label={`Remove ${p}`} onClick={() => void removePattern(p)}><X className="h-3 w-3" /></button>
            </span>
          ))}
          {patterns.length === 0 && <span className="text-xs text-muted-foreground">defaults: TOKEN / SECRET / PASSWORD / CLIENT_SECRET</span>}
        </div>
        <div className="flex gap-2">
          <Input value={pattern} onChange={e => setPattern(e.target.value)} placeholder="/MY_CUSTOM_KEY/" className="h-8 w-64 font-mono text-xs" />
          <Button size="sm" variant="outline" onClick={() => void addPattern()}><Plus className="mr-1 h-3.5 w-3.5" />Add</Button>
        </div>
      </div>

      {/* raw editor dialog */}
      <Dialog open={rawOpen} onOpenChange={o => { if (!o) setRawOpen(false) }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Raw .env</DialogTitle>
            <DialogDescription>Edit the whole file — each key=value must be on one line. Saved via bulk write (with backup).</DialogDescription>
          </DialogHeader>
          <textarea value={raw ?? ''} onChange={e => setRaw(e.target.value)} spellCheck={false}
            className="h-72 w-full rounded-lg border bg-muted/30 p-3 font-mono text-xs" />
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setRawOpen(false)}>Cancel</Button>
            <Button size="sm" onClick={() => void saveRaw()}><Upload className="mr-1.5 h-3.5 w-3.5" />Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* reveal-all confirm */}
      <Dialog open={confirmRevealAll} onOpenChange={setConfirmRevealAll}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reveal all secrets?</DialogTitle>
            <DialogDescription>This shows every sensitive value in plain text on screen. Anyone with view access will see them.</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setConfirmRevealAll(false)}>Cancel</Button>
            <Button size="sm" onClick={() => void revealAll()}>Reveal all</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function EnvTable({ rows, title, editing, setEditing, editVal, setEditVal, revealed, onUpdate, onReveal }: {
  rows: EnvVar[]
  title: string
  editing: string | null
  setEditing: (k: string) => void
  editVal: string
  setEditVal: (v: string) => void
  revealed: Set<string>
  onUpdate: (key: string, value: string | null) => Promise<void>
  onReveal: (key: string) => Promise<void>
}) {
  if (rows.length === 0) return null
  return (
    <div>
      <div className="mb-1.5 flex items-center gap-2 text-xs uppercase tracking-wide text-muted-foreground">
        {title} <Badge variant="outline">{rows.length}</Badge>
      </div>
      <div className="overflow-hidden rounded-lg border">
        {rows.map((v, i) => {
          const isEdit = editing === v.key
          const showVal = revealed.has(v.key) || !v.sensitive
          return (
            <div key={v.key} className={`flex items-center gap-2 px-3 py-2 text-sm ${i > 0 ? 'border-t' : ''}`}>
              <span className="w-52 truncate font-mono text-xs">{v.key}</span>
              {isEdit ? (
                <Input value={editVal} onChange={e => setEditVal(e.target.value)} className="h-8 flex-1 font-mono text-xs" autoFocus />
              ) : (
                <span className="flex-1 truncate font-mono text-xs text-muted-foreground">
                  {v.value && v.value !== '' ? (v.sensitive && !showVal ? v.masked : v.value) : <em className="text-[10px]">empty</em>}
                </span>
              )}
              {v.sensitive && (
                <Button size="icon-xs" variant="ghost" aria-label={`${revealed.has(v.key) ? 'Hide' : 'Reveal'} ${v.key}`} title={revealed.has(v.key) ? 'Hide' : 'Reveal value'} onClick={() => void onReveal(v.key)}>
                  {revealed.has(v.key) ? <EyeOff className="h-3 w-3" /> : <Eye className="h-3 w-3" />}
                </Button>
              )}
              {isEdit ? (
                <>
                  <Button size="icon-xs" variant="ghost" aria-label="Save" title="Save" onClick={() => void onUpdate(v.key, editVal)}><Save className="h-3 w-3" /></Button>
                  <Button size="icon-xs" variant="ghost" aria-label="Cancel" title="Cancel" onClick={() => setEditing('')}><X className="h-3 w-3" /></Button>
                </>
              ) : (
                <>
                  <Button size="icon-xs" variant="ghost" aria-label={`Edit ${v.key}`} title="Edit" onClick={() => { setEditing(v.key); setEditVal(v.value ?? '') }}><Save className="h-3 w-3 rotate-0" /></Button>
                  <Button size="icon-xs" variant="ghost" aria-label={`Delete ${v.key}`} title="Delete" onClick={() => void onUpdate(v.key, null)}><Trash2 className="h-3 w-3" /></Button>
                </>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
