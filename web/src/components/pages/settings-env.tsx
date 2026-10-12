import { useCallback, useEffect, useState } from 'react'
import { Eye, EyeOff, KeyRound, Plus, Save, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { fetchEnv, postEnv, type EnvVar } from '@/lib/api'
import { EmptyState } from '../empty'

/** Settings → Environment: manage .env variables from the dashboard. */
export function EnvSettings() {
  const [env, setEnv] = useState<EnvVar[] | null>(null)
  const [revealed, setRevealed] = useState<Set<string>>(new Set())
  const [editing, setEditing] = useState<string | null>(null)
  const [editVal, setEditVal] = useState('')
  const [newKey, setNewKey] = useState('')
  const [newVal, setNewVal] = useState('')

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
      </div>
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
