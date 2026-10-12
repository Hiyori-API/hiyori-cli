import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Archive, BellOff, CheckCircle2, Clock, Download, Gauge, ListChecks, Play, Pause,
  RefreshCw, RotateCcw, ServerCog, ShieldAlert, Terminal,
} from 'lucide-react'
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import {
  fetchAudit, fetchBackups, fetchLimits, postAction, postLimits, restoreBackup,
  runBackup, type AuditItem, type BackupItem,
} from '@/lib/api'
import { useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { ago, mb } from '@/lib/format'
import { useFailures } from '@/lib/fetch-cache'
import { tzStr, type Tz } from '@/lib/time'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'
import { SourceStatusBlip, SourceStatusLabel, sourceStatus } from '../source-status'
import { PageHead, Section } from '../widgets'

export const SOURCES = ['anilist', 'myanimelist', 'kitsu', 'anilist_manga', 'kitsu_manga', 'myanimelist_manga']
const SOURCE_LABEL: Record<string, string> = {
  anilist: 'AniList', myanimelist: 'MAL', kitsu: 'Kitsu',
  anilist_manga: 'AniList Manga', kitsu_manga: 'Kitsu Manga', myanimelist_manga: 'MAL Manga',
}

export function ManagementPage() {
  const { data } = useStatus()
  const { settings, update } = useSettings()
  const [backups, setBackups] = useState<BackupItem[]>([])
  const { failures, refresh: refreshFailures } = useFailures()
  const tz: Tz = settings.prefs?.tz === 'utc' ? 'utc' : 'local'
  const [audit, setAudit] = useState<AuditItem[]>([])
  const [limits, setLimits] = useState<{ max?: number; window?: number }>({})
  const [sources, setSources] = useState('anilist,myanimelist,kitsu')
  const [intervalH, setIntervalH] = useState(24)
  const [enabled, setEnabled] = useState(true)
  const [confirm, setConfirm] = useState<{ cmd: string; src: string } | null>(null)
  const [restorePick, setRestorePick] = useState<string | null>(null)
  const [max, setMax] = useState(100)
  const [win, setWin] = useState(60)
  const [loading, setLoading] = useState(true)

  const loadAll = useCallback(() => {
    void fetchBackups().then(r => setBackups(r.backups ?? []))
    void refreshFailures()
    void fetchAudit().then(r => setAudit(r.audit ?? []))
    void fetchLimits().then(r => {
      setLimits(r.limits ?? {})
      setMax(r.limits?.max ?? 100)
      setWin(r.limits?.window ?? 60)
    }).finally(() => setLoading(false))
  }, [refreshFailures])

  useEffect(() => { loadAll() }, [loadAll])

  useEffect(() => {
    const s = settings.schedule as { sources?: string; interval_hours?: number; enabled?: boolean } | undefined
    if (s?.sources) setSources(s.sources)
    if (s?.interval_hours) setIntervalH(Number(s.interval_hours))
    if (s?.enabled !== undefined) setEnabled(s.enabled)
  }, [settings])

  const circuit = Object.entries(data?.health?.checks?.rate_limiter?.limiters ?? {})
  const acks = (settings.prefs?.acks as string[] | undefined) ?? []
  const alerts = data?.alerts ?? []

  const run = async (cmd: string, src: string) => {
    await postAction(`ingest-${src}`, cmd as 'start' | 'retry' | 'pause' | 'resume')
    toast.success(`${src} → ${cmd}`)
    setConfirm(null)
  }

  const doBackup = async () => {
    await runBackup()
    toast('backup started')
    setTimeout(loadAll, 2000)
  }
  const doRestore = async () => {
    if (!restorePick) return
    const r = await restoreBackup(restorePick)
    if (r.error) toast.error(r.error)
    else { toast.success(`restore of ${restorePick} started`); setRestorePick(null) }
  }

  const saveSchedule = async () => {
    await update({ schedule: { sources, interval_hours: intervalH, enabled, updated_at: new Date().toISOString() } })
    toast.success('scheduler config saved')
  }
  const saveLimits = async () => {
    await postLimits({ max, window: win })
    toast.success('limits saved — applied on next ingest start')
    loadAll()
  }

  return (
    <div className="space-y-4">
      <PageHead title="Management" desc="Control plane — sources, scheduler, backups, safety and change log." />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {/* M1 + M3: Sources & scheduler */}
        <Section title="Sources & scheduler" icon={ServerCog} defaultOpen>
          {!data ? <Skeleton className="h-24 w-full" /> : (
            <div className="space-y-2">
              {SOURCES.map(src => {
                const job = data?.jobs[`ingest-${src}`]
                const phase = job?.phase ?? 'idle'
                const paused = data?.controls?.[`ingest-${src}`]?.paused
                return (
                  <div key={src} className="flex items-center gap-2 rounded-lg border px-3 py-2">
                    <SourceStatusBlip status={sourceStatus(data?.health, src)} size="sm" />
                    <span className="flex-1 text-sm">{SOURCE_LABEL[src] ?? src}<span className="ml-1 text-[10px] text-muted-foreground">{src}</span></span>
                    <SourceStatusLabel status={sourceStatus(data?.health, src)} />
                    <Badge variant={phase === 'done' ? 'secondary' : phase === 'running' ? 'outline' : 'ghost'}>{paused ? 'paused' : phase}</Badge>
                    <Button size="icon-sm" variant="ghost" title="Start" aria-label={`Start ${src}`} onClick={() => setConfirm({ cmd: 'start', src })}><Play className="h-3.5 w-3.5" /></Button>
                    <Button size="icon-sm" variant="ghost" title="Pause" aria-label={`Pause ${src}`} onClick={() => void run('pause', src)}><Pause className="h-3.5 w-3.5" /></Button>
                    <Button size="icon-sm" variant="ghost" title="Resume" aria-label={`Resume ${src}`} onClick={() => void run('resume', src)}><RefreshCw className="h-3.5 w-3.5" /></Button>
                    <Button size="icon-sm" variant="ghost" title="Retry from scratch" aria-label={`Retry ${src}`} onClick={() => setConfirm({ cmd: 'retry', src })}><RotateCcw className="h-3.5 w-3.5" /></Button>
                  </div>
                )
              })}
              <div className="grid grid-cols-2 gap-2 rounded-lg border p-3 text-sm">
                <div className="col-span-2 text-xs text-muted-foreground">Scheduler</div>
                <Input value={sources} onChange={e => setSources(e.target.value)} placeholder="comma-separated sources" className="col-span-2 h-8 text-xs" />
                <div className="flex items-center gap-2">
                  <Input type="number" value={intervalH} onChange={e => setIntervalH(Number(e.target.value))} className="h-8 w-20 text-xs" />
                  <span className="text-xs text-muted-foreground">h interval</span>
                </div>
                <div className="flex items-center gap-2">
                  <Switch checked={enabled} onCheckedChange={setEnabled} />
                  <span className="text-xs text-muted-foreground">enabled</span>
                </div>
                <Button size="sm" className="col-span-2" onClick={() => void saveSchedule()}>Save scheduler</Button>
                <Button size="sm" variant="outline" className="col-span-2" onClick={() => { void postAction('schedule', 'runnow'); toast('all sources triggered') }}>
                  <Terminal className="mr-1.5 h-3.5 w-3.5" />Run now
                </Button>
              </div>
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Clock className="h-3.5 w-3.5" />
                next scheduled run in {data?.schedule?.next_in != null ? `${Math.round(data.schedule.next_in / 60)}m` : '—'}
              </div>
            </div>
          )}
        </Section>

        {/* M4 + M5: Backups */}
        <Section title="Backups" icon={Archive}>
          <div className="space-y-2">
            {backups.length === 0 ? (
              <EmptyState icon={Archive} title="No backups yet" description="Run one to protect the dataset." action={doBackup} actionLabel="Backup now" />
            ) : (
              <BackupsTable backups={backups} onRestore={setRestorePick} />
            )}
            <Button size="sm" variant="outline" className="w-full" onClick={doBackup}><Archive className="mr-1.5 h-3.5 w-3.5" />Backup now</Button>
          </div>
        </Section>

        {/* M6 + M9: Circuits & limits */}
        <Section title="Rate limiter & circuits" icon={Gauge}>
          <div className="space-y-2 text-sm">
            {circuit.length === 0 && <p className="text-xs text-muted-foreground">No limiter state reported yet (needs an ingestion run).</p>}
            {circuit.map(([src, s]) => (
              <div key={src} className="flex items-center gap-2 rounded-lg border px-3 py-2">
                <Badge variant={s.circuit_state === 'open' ? 'destructive' : 'secondary'}>{s.circuit_state === 'open' ? 'OPEN' : 'closed'}</Badge>
                <span className="flex-1 text-xs">{src}</span>
                <span className="text-xs tabular-nums text-muted-foreground">tokens {s.tokens_available}/{s.max_tokens}</span>
              </div>
            ))}
            <div className="rounded-lg border p-3">
              <div className="mb-2 text-xs text-muted-foreground">Overrides (applied to ingest processes on start)</div>
              <div className="flex items-center gap-2">
                <Input type="number" value={max} onChange={e => setMax(Number(e.target.value))} className="h-8 w-24 text-xs" aria-label="max requests" />
                <span className="text-xs text-muted-foreground">req /</span>
                <Input type="number" value={win} onChange={e => setWin(Number(e.target.value))} className="h-8 w-24 text-xs" aria-label="window seconds" />
                <span className="text-xs text-muted-foreground">s</span>
                <Button size="sm" variant="outline" onClick={() => void saveLimits()}>Save</Button>
              </div>
              <p className="mt-2 text-[10px] text-muted-foreground">current: {limits.max ?? '—'} req / {limits.window ?? '—'}s · {limits.max ? 'active env' : ''}</p>
            </div>
          </div>
        </Section>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {/* M8: Alerts */}
        <Section title="Alerts & acknowledgements" icon={ShieldAlert}>
          {alerts.length === 0 ? (
            <EmptyState icon={CheckCircle2} title="No active alerts" description="Circuit breaks and API outages will appear here." />
          ) : (
            <div className="space-y-1.5">
              {alerts.map((a, i) => (
                <div key={i} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                  <span className={`h-2 w-2 rounded-full ${a.level === 'critical' ? 'bg-red-500' : 'bg-amber-500'}`} />
                  <span className="flex-1">{a.msg}</span>
                  {acks.includes(a.msg ?? '') ? <Badge variant="secondary">acked</Badge> : (
                    <Button size="sm" variant="ghost" onClick={() => void update({ prefs: { ...settings.prefs, acks: [...acks, a.msg ?? ''] } })}><BellOff className="mr-1 h-3.5 w-3.5" />Ack</Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </Section>

        {/* M7: Retry queue */}
        <Section title="Validation failures" icon={ListChecks} badge={<Badge variant="outline">{failures.length}</Badge>}>
          {failures.length === 0 ? (
            <EmptyState icon={ListChecks} title="No validation issues" description="Failed entries and violations appear here for retry." />
          ) : (
            <div className="max-h-64 space-y-1.5 overflow-auto text-xs">
              {failures.map((f, i) => (
                <div key={i} className="rounded-lg border px-2 py-1.5">
                  <div className="flex items-center gap-2">
                    <Badge variant="outline">{f.source ?? '?'}</Badge>
                    <span className="flex-1 truncate">{f.title ?? '(no title)'}</span>
                    <Button size="icon-xs" variant="ghost" title="Retry source" onClick={() => void run('retry', f.source ?? 'anilist')}><RotateCcw className="h-3 w-3" /></Button>
                  </div>
                  <div className="mt-1 text-amber-500">{Object.keys(f.errors ?? {}).join(', ')}</div>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>

      {/* M10: audit */}
      <Section title="Audit log" icon={Terminal}>
        {loading ? <Skeleton className="h-24 w-full" /> : audit.length === 0 ? (
          <EmptyState icon={Terminal} title="No actions logged" description="Management actions will be recorded here." />
        ) : (
          <AuditTable audit={audit} tz={tz} />
        )}
      </Section>

      {/* confirm dialog */}
      <Dialog open={!!confirm} onOpenChange={o => !o && setConfirm(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{confirm?.cmd === 'retry' ? 'Full re-ingest?' : confirm?.cmd === 'start' ? 'Start source?' : 'Confirm'}</DialogTitle>
            <DialogDescription>
              {confirm?.cmd === 'retry'
                ? `This restarts ${confirm?.src} from the beginning (existing docs are skipped).`
                : `This starts an ingestion job for ${confirm?.src}.`}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setConfirm(null)}>Cancel</Button>
            <Button size="sm" onClick={() => confirm && void run(confirm.cmd, confirm.src)}>{confirm?.cmd}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* restore confirm */}
      <Dialog open={!!restorePick} onOpenChange={o => !o && setRestorePick(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Restore {restorePick}?</DialogTitle>
            <DialogDescription>This drops the current hiyori database and replaces it with the backup archive. Proceed with care.</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setRestorePick(null)}>Cancel</Button>
            <Button size="sm" variant="destructive" onClick={() => void doRestore()}>Restore</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

const backupHelper = createColumnHelper<DataTableFeatures, BackupItem>()

function BackupsTable({ backups, onRestore }: { backups: BackupItem[]; onRestore: (file: string | null) => void }) {
  const columns = useMemo<ColumnDef<DataTableFeatures, BackupItem, any>[]>(() => [
    backupHelper.accessor('file', {
      id: 'file',
      header: 'Archive',
      meta: { truncate: true },
      cell: ({ row }) => <span className="text-muted-foreground">{row.original.file}</span>,
    }),
    backupHelper.accessor(r => r.size ?? 0, {
      id: 'size',
      header: 'Size',
      sortFn: 'basic',
      meta: { align: 'right' },
      cell: ({ row }) => <span className="tabular-nums">{mb(row.original.size)}</span>,
    }),
    backupHelper.accessor(r => r.mtime ?? 0, {
      id: 'mtime',
      header: 'Modified',
      sortFn: 'basic',
      cell: ({ row }) => <span className="text-muted-foreground">{ago(row.original.mtime)}</span>,
    }),
    backupHelper.display({
      id: 'actions',
      enableSorting: false,
      meta: { align: 'right' },
      cell: ({ row }) => {
        const file = row.original.file
        return (
          <div className="flex items-center justify-end gap-1">
            <a href={`/storage/backups/${file}`} download={file} className="text-muted-foreground hover:text-foreground" title="Download"><Download className="h-3.5 w-3.5" /></a>
            <Button size="icon-xs" variant="ghost" title="Restore" aria-label={`Restore ${file}`} onClick={() => onRestore(file ?? null)}><RotateCcw className="h-3.5 w-3.5" /></Button>
          </div>
        )
      },
    }),
  ], [onRestore])

  return (
    <DataTable<BackupItem>
      columns={columns}
      data={backups}
      getRowId={(b, i) => b.file ?? `backup-${i}`}
      initialPageSize={10}
      pageSizeOptions={[5, 10, 25]}
      maxHeight="16rem"
      ariaLabel="Backups"
      footerLeft={`${backups.length} backups`}
    />
  )
}

const auditHelper = createColumnHelper<DataTableFeatures, AuditItem>()

function AuditTable({ audit, tz }: { audit: AuditItem[]; tz: Tz }) {
  const columns = useMemo<ColumnDef<DataTableFeatures, AuditItem, any>[]>(() => [
    auditHelper.accessor('ts', {
      id: 'ts',
      header: 'When',
      cell: ({ row }) => <span className="tabular-nums text-muted-foreground">{row.original.ts ? tzStr(row.original.ts, tz) : ''}</span>,
    }),
    auditHelper.accessor('action', {
      id: 'action',
      header: 'Action',
      cell: ({ row }) => <Badge variant="outline">{row.original.action ?? ''}</Badge>,
    }),
    auditHelper.accessor('detail', {
      id: 'detail',
      header: 'Detail',
      meta: { truncate: true },
      cell: ({ row }) => <span className="text-muted-foreground">{JSON.stringify(row.original.detail ?? {})}</span>,
    }),
    auditHelper.accessor('ip', {
      id: 'ip',
      header: 'IP',
      meta: { align: 'right' },
      cell: ({ row }) => <span className="text-muted-foreground">{row.original.ip}</span>,
    }),
  ], [tz])

  return (
    <DataTable<AuditItem>
      columns={columns}
      data={audit}
      getRowId={(a, i) => `${a.ts ?? ''}-${a.action ?? ''}-${i}`}
      initialPageSize={15}
      pageSizeOptions={[10, 15, 25, 50]}
      maxHeight="16rem"
      ariaLabel="Audit log"
      footerLeft={`${audit.length} actions`}
    />
  )
}