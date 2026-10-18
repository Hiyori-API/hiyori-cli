import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Archive, BellOff, CheckCircle2, Clock, Download, Gauge, ListChecks, Loader2, MoreHorizontal,
  Pause, Play, RefreshCw, RotateCcw, ServerCog, ShieldAlert, Terminal,
} from 'lucide-react'
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  fetchAudit, fetchBackups, fetchLimits, jobPhase, postAction, postLimits, restoreBackup,
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
import { Notice } from '../notice'
import { PageHead, Section } from '../widgets'

const SOURCES = ['anilist', 'myanimelist', 'kitsu', 'anilist_manga', 'kitsu_manga', 'myanimelist_manga']
const SOURCE_LABEL: Record<string, string> = {
  anilist: 'AniList', myanimelist: 'MAL', kitsu: 'Kitsu',
  anilist_manga: 'AniList Manga', kitsu_manga: 'Kitsu Manga', myanimelist_manga: 'MAL Manga',
}

const AUDIT_FILTERS = [
  { value: 'all', label: 'All actions' },
  { value: 'env.', label: 'env.*' },
  { value: 'job.', label: 'job.*' },
  { value: 'settings.', label: 'settings.*' },
  { value: 'restore', label: 'restore' },
  { value: 'schedule.', label: 'schedule.*' },
]

/** M4 — cap on the number of validation-failure groups shown before the toggle. */
const MAX_FAILURE_GROUPS = 30
/** M6 — audit log is capped to the latest 50 entries. */
const AUDIT_CAP = 50

export function ManagementPage() {
  const { data } = useStatus()
  const { settings, update } = useSettings()
  const [backups, setBackups] = useState<BackupItem[]>([])
  const { failures, total: failureTotal, refresh: refreshFailures } = useFailures()
  const tz: Tz = settings.prefs?.tz === 'utc' ? 'utc' : 'local'
  const [audit, setAudit] = useState<AuditItem[]>([])
  const [auditFilter, setAuditFilter] = useState('all')
  const [limits, setLimits] = useState<{ max?: number; window?: number; source?: string }>({})
  const [confirm, setConfirm] = useState<{ cmd: string; src: string } | null>(null)
  // Same pattern as the Dashboard: an action that spawns a worker shows its
  // spinner immediately rather than looking inert until the next poll.
  const [busySrc, setBusySrc] = useState<string | null>(null)
  const [runnowBusy, setRunnowBusy] = useState(false)
  const [restorePick, setRestorePick] = useState<string | null>(null)
  const [max, setMax] = useState(100)
  const [win, setWin] = useState(60)
  const [showAllFailures, setShowAllFailures] = useState(false)
  const [showAcked, setShowAcked] = useState(false)
  const [loading, setLoading] = useState(true)
  const [auditTotal, setAuditTotal] = useState(0)

  const [loadError, setLoadError] = useState<string | null>(null)
  const loadAll = useCallback(() => {
    setLoadError(null)
    void fetchBackups()
      .then(r => setBackups(r.backups ?? []))
      .catch((e: unknown) => setLoadError(e instanceof Error ? e.message : String(e)))
    void refreshFailures()
    void fetchAudit().then(r => {
      setAudit(r.audit ?? [])
      setAuditTotal(r.total ?? (r.audit?.length ?? 0))
    }).catch((e: unknown) => setLoadError(e instanceof Error ? e.message : String(e)))
    void fetchLimits().then(r => {
      setLimits(r.limits ?? {})
      setMax(r.limits?.max ?? 100)
      setWin(r.limits?.window ?? 60)
    }).catch((e: unknown) => setLoadError(e instanceof Error ? e.message : String(e)))
      .finally(() => setLoading(false))
  }, [refreshFailures])

  useEffect(() => { loadAll() }, [loadAll])

  const circuit = Object.entries(data?.health?.checks?.rate_limiter?.limiters ?? {})
  const acks = (settings.prefs?.acks as string[] | undefined) ?? []
  const alerts = data?.alerts ?? []

  // M1 — scheduler is read-only (managed via `schedule --install` / SCHEDULE_* env).
  const sched = data?.schedule
  const schedCfg = settings.schedule as { sources?: string; interval_hours?: number; enabled?: boolean } | undefined
  const scheduleSources = sched?.sources ?? schedCfg?.sources ?? '—'
  const intervalH = sched?.interval_hours ?? schedCfg?.interval_hours
  const scheduleEnabled = schedCfg?.enabled

  // M4 — validation failures deduped by source + title, one retry per group.
  const failureGroups = useMemo(() => {
    const m = new Map<string, { source: string; title: string; count: number; errors: Set<string> }>()
    for (const f of failures) {
      const source = f.source ?? '?'
      const title = f.title ?? '(no title)'
      const key = `${source}\u0000${title}`
      let g = m.get(key)
      if (!g) { g = { source, title, count: 0, errors: new Set<string>() }; m.set(key, g) }
      g.count++
      for (const k of Object.keys(f.errors ?? {})) g.errors.add(k)
    }
    return Array.from(m.values())
  }, [failures])
  const visibleGroups = showAllFailures ? failureGroups : failureGroups.slice(0, MAX_FAILURE_GROUPS)

  // M5 — acked alerts hidden by default.
  const hasAcked = alerts.some(a => acks.includes(a.msg ?? ''))
  const visibleAlerts = showAcked ? alerts : alerts.filter(a => !acks.includes(a.msg ?? ''))

  // M6 — audit capped at 50 + action-type filter.
  const visibleAudit = useMemo(() => {
    const list = auditFilter === 'all' ? audit : audit.filter(a => (a.action ?? '').startsWith(auditFilter))
    return list.slice(0, AUDIT_CAP)
  }, [audit, auditFilter])

  // M7 — newest backup for the header line.
  const lastBackup = useMemo(
    () => backups.reduce<BackupItem | undefined>((acc, b) => ((b.mtime ?? 0) > (acc?.mtime ?? 0) ? b : acc), undefined),
    [backups],
  )

  const run = async (cmd: string, src: string) => {
    setBusySrc(src)
    setConfirm(null)
    let ok = false
    try {
      const res = await postAction(`ingest-${src}`, cmd as 'start' | 'retry' | 'pause' | 'resume')
      if (res.error) throw new Error(res.error)
      ok = true
      toast.success(`${src} → ${cmd}`)
    } catch (e) {
      // try/finally without a catch swallowed every failed action: no toast, no
      // log, the row just sat there. Say what failed.
      toast.error(`${src} → ${cmd} failed — ${e instanceof Error ? e.message : String(e)}`)
    } finally {
      // Hold the spinner past a successful response so it covers the poll that
      // actually reflects the change, rather than blinking off straight away.
      // On failure there is nothing to wait for, so clear it now.
      if (ok) window.setTimeout(() => setBusySrc(b => (b === src ? null : b)), 2000)
      else setBusySrc(b => (b === src ? null : b))
    }
  }

  // Await the trigger before announcing success — the old order toasted "all
  // sources triggered" even when the request never reached the server.
  const runNow = async () => {
    setRunnowBusy(true)
    try {
      const res = await postAction('schedule', 'runnow')
      if (res.error) throw new Error(res.error)
      toast.success('all sources triggered')
    } catch (e) {
      toast.error(`could not trigger all sources — ${e instanceof Error ? e.message : String(e)}`)
    } finally {
      setRunnowBusy(false)
    }
  }

  const doBackup = async () => {
    await runBackup()
    toast('backup started')
    setTimeout(loadAll, 2000)
  }
  const doRestore = async () => {
    if (!restorePick) return
    try {
      const r = await restoreBackup(restorePick)
      if (r.error) toast.error(r.error)
      else { toast.success(`restore of ${restorePick} started`); setRestorePick(null) }
    } catch (e) {
      toast.error(`restore failed — ${e instanceof Error ? e.message : String(e)}`)
    }
  }

  const saveLimits = async () => {
    try {
      await postLimits({ max, window: win })
      toast.success('limits saved — applied on next ingest start')
      loadAll()
    } catch (e) {
      toast.error(`limits not saved — ${e instanceof Error ? e.message : String(e)}`)
    }
  }

  return (
    <div className="space-y-4">
      <PageHead title="Ingest" desc="Sources, scheduler and backups — plus the safety and change log." />

      {loadError && (
        <Notice tone="error" action={<Button size="sm" variant="outline" onClick={loadAll}>Retry</Button>}>
          Some panels could not load — {loadError}
        </Notice>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {/* M1 + M2: Sources & scheduler */}
        <Section title="Sources & scheduler" icon={ServerCog} defaultOpen>
          {!data ? <Skeleton className="h-24 w-full" /> : (
            <div className="space-y-2">
              {SOURCES.map(src => {
                const job = data?.jobs[`ingest-${src}`]
                const phase = jobPhase(job)
                const paused = data?.controls?.[`ingest-${src}`]?.paused
                return (
                  <div key={src} className="flex items-center gap-2 rounded-lg border px-3 py-2">
                    <SourceStatusBlip status={sourceStatus(data?.health, src)} size="sm" />
                    <span className="flex-1 text-sm">{SOURCE_LABEL[src] ?? src}<span className="ml-1 text-[10px] text-muted-foreground">{src}</span></span>
                    <SourceStatusLabel status={sourceStatus(data?.health, src)} />
                    <Badge variant={phase === 'done' ? 'secondary' : phase === 'running' ? 'outline' : 'ghost'}>{paused ? 'paused' : phase}</Badge>
                    <DropdownMenu>
                      <DropdownMenuTrigger
                        render={
                          <Button size="icon-sm" variant="ghost" aria-label={`Actions for ${src}`} disabled={busySrc === src}>
                            {busySrc === src
                              ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
                              : <MoreHorizontal className="h-3.5 w-3.5" />}
                          </Button>
                        }
                      />
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => setConfirm({ cmd: 'start', src })}><Play className="h-3.5 w-3.5" />Start</DropdownMenuItem>
                        <DropdownMenuItem disabled={!!paused} onClick={() => void run('pause', src)}><Pause className="h-3.5 w-3.5" />Pause</DropdownMenuItem>
                        <DropdownMenuItem disabled={!paused} onClick={() => void run('resume', src)}><RefreshCw className="h-3.5 w-3.5" />Resume</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" onClick={() => setConfirm({ cmd: 'retry', src })}><RotateCcw className="h-3.5 w-3.5" />Retry</DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                )
              })}
              <div className="grid grid-cols-1 gap-2 rounded-lg border p-3 text-sm sm:grid-cols-2">
                <div className="col-span-full text-xs text-muted-foreground">Scheduler</div>
                <div className="flex items-center gap-2 text-xs">
                  <span className="text-muted-foreground">Sources</span>
                  <span className="truncate font-medium">{scheduleSources}</span>
                </div>
                <div className="flex items-center gap-2 text-xs">
                  <span className="text-muted-foreground">Interval</span>
                  <span className="font-medium">{intervalH != null ? `${intervalH}h` : '—'}</span>
                </div>
                <div className="flex items-center gap-2 text-xs">
                  <span className="text-muted-foreground">Enabled</span>
                  <span className="font-medium">{scheduleEnabled == null ? '—' : scheduleEnabled ? 'yes' : 'no'}</span>
                </div>
                <div className="flex items-center gap-2 text-xs">
                  <Clock className="h-3.5 w-3.5" />
                  <span className="text-muted-foreground">Next run</span>
                  <span className="font-medium">{sched?.next_in != null ? `in ${Math.round(sched.next_in / 60)}m` : '—'}</span>
                </div>
                <p className="col-span-full text-[10px] text-muted-foreground">Managed via <code>hiyori schedule --install</code> or SCHEDULE_* env vars — edit there, not here.</p>
                <Button size="sm" variant="outline" className="col-span-full" disabled={runnowBusy} onClick={() => void runNow()}>
                  {runnowBusy
                    ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                    : <Terminal className="mr-1.5 h-3.5 w-3.5" />}
                  {runnowBusy ? 'Triggering…' : 'Run now'}
                </Button>
              </div>
            </div>
          )}
        </Section>

        {/* M7: Backups */}
        <Section title="Backups" icon={Archive}>
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" onClick={doBackup}><Archive className="mr-1.5 h-3.5 w-3.5" />Backup now</Button>
              <span className="text-xs text-muted-foreground">
                {lastBackup?.file
                  ? <>Last backup: <span className="font-medium">{lastBackup.file}</span> <span className="tabular-nums">({ago(lastBackup.mtime)})</span></>
                  : 'No backups yet.'}
              </span>
            </div>
            {backups.length === 0 ? (
              <EmptyState icon={Archive} title="No backups yet" description="Run one to protect the dataset." />
            ) : (
              <BackupsTable backups={backups} onRestore={setRestorePick} />
            )}
          </div>
        </Section>

        {/* M3: Rate limiter & circuits */}
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
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {/* M5: Alerts & acknowledgements */}
        <Section title="Alerts & acknowledgements" icon={ShieldAlert}>
          <div className="space-y-1.5">
            {hasAcked && (
              <div className="flex justify-end">
                <Button size="sm" variant="ghost" onClick={() => setShowAcked(s => !s)}>{showAcked ? 'Hide acked' : 'Show acked'}</Button>
              </div>
            )}
            {visibleAlerts.length === 0 ? (
              <EmptyState icon={CheckCircle2} title="No active alerts" description="Circuit breaks and API outages will appear here." />
            ) : visibleAlerts.map((a, i) => (
              <div key={i} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                <span className={`h-2 w-2 rounded-full ${a.level === 'critical' ? 'bg-red-500' : 'bg-warn'}`} />
                <span className="flex-1">{a.msg}</span>
                {acks.includes(a.msg ?? '') ? <Badge variant="secondary">acked</Badge> : (
                  <Button size="sm" variant="ghost" onClick={() => void update({ prefs: { ...settings.prefs, acks: [...acks, a.msg ?? ''] } })}><BellOff className="mr-1 h-3.5 w-3.5" />Ack</Button>
                )}
              </div>
            ))}
          </div>
        </Section>

        {/* M4: Validation failures */}
        <Section title="Validation failures" icon={ListChecks} badge={<Badge variant="outline" title={`${failureTotal} logged; showing the newest ${failures.length}`}>{failureTotal > failures.length ? `${failures.length}/${failureTotal}` : failures.length}</Badge>}>
          {failureGroups.length === 0 ? (
            <EmptyState icon={ListChecks} title="No validation issues" description="Failed entries and violations appear here for retry." />
          ) : (
            <div className="space-y-1.5">
              <ScrollArea className="max-h-64">
                <div className="space-y-1.5 text-xs">
                  {visibleGroups.map((g, i) => (
                    <div key={`${g.source}-${g.title}-${i}`} className="rounded-lg border px-2 py-1.5">
                      <div className="flex items-center gap-2">
                        <Badge variant="outline">{g.source}</Badge>
                        <span className="min-w-0 flex-1 truncate" title={g.title}>{g.title}</span>
                        <Badge variant="secondary" title={`${g.count} entries`}>×{g.count}</Badge>
                        <Button
                          size="icon-xs"
                          variant="ghost"
                          title={`Retry ${g.source}`}
                          aria-label={`Retry source ${g.source}`}
                          onClick={() => void run('retry', g.source === '?' ? 'anilist' : g.source)}
                        ><RotateCcw className="h-3 w-3" /></Button>
                      </div>
                      {g.errors.size > 0 && <div className="mt-1 text-warn">{Array.from(g.errors).join(', ')}</div>}
                    </div>
                  ))}
                </div>
              </ScrollArea>
              {failureGroups.length > MAX_FAILURE_GROUPS && (
                <Button size="sm" variant="ghost" className="w-full" onClick={() => setShowAllFailures(s => !s)}>
                  {showAllFailures ? 'Show fewer' : `Show all (${failureGroups.length})`}
                </Button>
              )}
            </div>
          )}
        </Section>
      </div>

      {/* M6: audit */}
      <Section title="Audit log" icon={Terminal}>
        {loading ? <Skeleton className="h-24 w-full" /> : audit.length === 0 ? (
          <EmptyState icon={Terminal} title="No actions logged" description="Management actions will be recorded here." />
        ) : (
          <div className="space-y-2">
            <div className="flex items-center justify-end">
              <Select value={auditFilter} onValueChange={v => setAuditFilter(v ?? 'all')}>
                <SelectTrigger className="h-8 w-40" aria-label="Filter audit log by action"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {AUDIT_FILTERS.map(f => <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <AuditTable audit={visibleAudit} total={auditTotal} tz={tz} />
          </div>
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
      header: 'Actions',
      enableSorting: false,
      meta: { align: 'right' },
      cell: ({ row }) => {
        const file = row.original.file
        return (
          <div className="flex items-center justify-end gap-1">
            <a href={`/storage/backups/${file}`} download={file} aria-label={`Download ${file}`} title={`Download ${file}`} className="inline-flex h-6 w-6 items-center justify-center rounded-sm text-muted-foreground hover:bg-muted/50 hover:text-foreground"><Download className="h-3.5 w-3.5" /></a>
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

function AuditTable({ audit, tz, total }: { audit: AuditItem[]; tz: Tz; total: number }) {
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
      footerLeft={`${audit.length} of ${total} actions`}
    />
  )
}