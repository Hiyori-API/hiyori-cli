import { lazy, Suspense, useEffect, useState } from 'react'
import { Activity, Database, GitMerge, LayoutDashboard, Loader2, ScrollText, Settings as SettingsIcon, SlidersHorizontal, Upload } from 'lucide-react'
import {
  Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupContent, SidebarGroupLabel,
  SidebarHeader, SidebarInset, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarProvider, SidebarRail, SidebarTrigger,
} from '@/components/ui/sidebar'
import { cn } from 'cn'
import { AlertToasts } from './alert-toast'
import { ErrorBoundary } from './error-boundary'
import { StatusProvider, useStatus } from '@/lib/status-context'
import { useSettings } from '@/lib/hooks'
import { isIngestJob, isJobLive, type Job } from '@/lib/api'

const DashboardPage = lazy(() => import('./pages/DashboardPage').then(m => ({ default: m.DashboardPage })))
const ExplorerPage = lazy(() => import('./pages/ExplorerPage').then(m => ({ default: m.ExplorerPage })))
const ManagementPage = lazy(() => import('./pages/ManagementPage').then(m => ({ default: m.ManagementPage })))
const QualityPage = lazy(() => import('./pages/QualityPage').then(m => ({ default: m.QualityPage })))
const LogsPage = lazy(() => import('./pages/LogsPage').then(m => ({ default: m.LogsPage })))
const PublishPage = lazy(() => import('./pages/PublishPage').then(m => ({ default: m.PublishPage })))
const SettingsPage = lazy(() => import('./pages/SettingsPage').then(m => ({ default: m.SettingsPage })))

type View = 'dashboard' | 'explorer' | 'management' | 'quality' | 'logs' | 'publish' | 'settings'

interface NavItem {
  id: View
  label: string
  icon: typeof LayoutDashboard
  desc: string
  /** One-line statement of what this stage does, shown as the nav tooltip. */
  stage?: string
}

/**
 * Navigation follows the pipeline, not a filing cabinet.
 *
 * The previous list was flat and alphabetical-ish — Publish sat between Logs
 * and Settings — so nothing told an operator where they were in the run. These
 * groups map to the four things the tool actually does, in the order it does
 * them: watch, ingest, merge, ship. Inspection is separate because browsing and
 * debugging are not stages of the pipeline, they are things you do *about* it.
 */
const NAV_GROUPS: Array<{ label: string; items: NavItem[] }> = [
  {
    label: 'Overview',
    items: [
      { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, desc: 'Live telemetry' },
    ],
  },
  {
    label: 'Pipeline',
    items: [
      { id: 'management', label: 'Ingest', icon: SlidersHorizontal, desc: 'Sources, scheduler, backups', stage: 'Pull records from the upstream APIs' },
      { id: 'quality', label: 'Merge quality', icon: GitMerge, desc: 'Coverage, benchmarks, sampler', stage: 'Decide whether the merge is good' },
      { id: 'publish', label: 'Publish', icon: Upload, desc: 'Export & publish dump', stage: 'Ship the merged output' },
    ],
  },
  {
    label: 'Inspect',
    items: [
      { id: 'explorer', label: 'Explorer', icon: Database, desc: 'MongoDB browser' },
      { id: 'logs', label: 'Logs', icon: ScrollText, desc: 'Slow requests, validation, runs' },
    ],
  },
]

const NAV: NavItem[] = NAV_GROUPS.flatMap(g => g.items)
const SETTINGS_ITEM: NavItem = { id: 'settings', label: 'Settings', icon: SettingsIcon, desc: 'Preferences' }

function PipelineChipDivider() {
  return <span aria-hidden="true" className="h-4 w-px bg-border" />
}

/** Lazy page-load fallback: centered spinner while a chunk streams in. */
function PageFallback() {
  return (
    <div className="flex h-[60vh] items-center justify-center">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
    </div>
  )
}


/**
 * Reconcile persisted interface preferences onto <html>.
 *
 * `index.html` ships `class="dark"` so the first paint is not a white flash,
 * but that meant a saved "Light" preference was lost on every reload: the only
 * code that touched the class was the Settings toggle, so light mode only
 * survived until you navigated away and came back. Applying it here — once, at
 * the shell — makes the saved preference win on boot without the flash.
 */
function useAppliedPreferences(): void {
  const { settings } = useSettings()
  const theme = settings.prefs?.theme
  const density = settings.prefs?.density
  useEffect(() => {
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.classList.toggle('dark', theme === 'dark')
    }
  }, [theme])
  useEffect(() => {
    document.documentElement.classList.toggle('density-compact', density === 'compact')
  }, [density])
}


/**
 * Pipeline state, in the shell so it survives navigation.
 *
 * The header previously carried only connection health, so a forty-minute ingest
 * stopped being visible the moment you went to Logs to investigate it.
 *
 * Names the source when there is one, because "1 ingesting" tells you a count
 * and nothing else — not which run, and not whether it is moving.
 */
function PipelineChip() {
  const { data } = useStatus()
  const jobs = data?.jobs ?? {}
  const liveKeys = Object.keys(jobs).filter(k => isIngestJob(k, jobs[k] as Job) && isJobLive(jobs[k] as Job))

  const pill = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs tabular-nums text-muted-foreground'

  if (liveKeys.length === 0) {
    return (
      <span className={pill} title="No ingestion running">
        <span className="h-2 w-2 shrink-0 rounded-full bg-muted-foreground/40" />
        idle
      </span>
    )
  }

  const rate = liveKeys.reduce((a, k) => a + ((jobs[k] as Job).per_second ?? 0), 0)
  const errors = liveKeys.reduce((a, k) => a + ((jobs[k] as Job).errors ?? 0), 0)
  const name = liveKeys.length === 1
    ? String((jobs[liveKeys[0]] as Job).source ?? liveKeys[0])
    : `${liveKeys.length} sources`
  // A run that is live but moving at nothing is a real state worth naming —
  // it usually means every page is being skipped, which a bare "0.0/s" hides.
  const stalled = rate < 0.05

  return (
    <span
      className={pill}
      title={`${liveKeys.join(', ')} · ${rate.toFixed(1)}/s combined${errors ? ` · ${errors} errors` : ''}`}
    >
      <span className={cn('h-2 w-2 shrink-0 rounded-full', stalled ? 'bg-amber-500' : 'animate-pulse bg-emerald-500')} />
      <span className="max-w-40 truncate">{name}</span>
      <span className="text-muted-foreground/60">·</span>
      {stalled ? 'no progress' : `${rate.toFixed(1)}/s`}
    </span>
  )
}

/** Single header connection chip (G7) — realtime toggle lives in Settings now. */
function StatusChip() {
  const { realtime, sseOpen, sseReconnects, refreshMs } = useStatus()
  const [online, setOnline] = useState<boolean>(navigator.onLine)
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) }
  }, [])

  const secs = Math.max(1, Math.round(refreshMs / 1000))
  let dot = 'bg-emerald-500'
  let hollow = false
  let pulse = false
  let label: string
  if (!online) {
    dot = 'bg-red-500'
    label = 'offline · cached'
  } else if (realtime) {
    if (sseOpen) {
      dot = 'bg-emerald-500'
      pulse = true
      label = `live · ${secs}s`
    } else {
      hollow = true
      label = `reconnecting (${sseReconnects})`
    }
  } else {
    dot = 'bg-sky-500'
    label = `polling · ${secs}s`
  }

  return (
    <span role="status" title="Connection status" className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs tabular-nums text-muted-foreground">
      <span className={`h-2 w-2 rounded-full ${hollow ? 'border border-amber-500 bg-transparent' : dot} ${pulse ? 'animate-pulse' : ''}`} />
      {label}
    </span>
  )
}

export function Dashboard() {
  useAppliedPreferences()
  const [view, setView] = useState<View>('dashboard')
  const nav = [...NAV, SETTINGS_ITEM].find(n => n.id === view) ?? NAV[0]

  // Cross-page navigation (e.g. Settings → "Open Publish page") without a router.
  useEffect(() => {
    const onNavigate = (e: Event) => {
      const detail = (e as CustomEvent<string>).detail
      if ([...NAV, SETTINGS_ITEM].some(n => n.id === detail)) setView(detail as View)
    }
    window.addEventListener('hiyori:navigate', onNavigate)
    return () => window.removeEventListener('hiyori:navigate', onNavigate)
  }, [])

  return (
    <StatusProvider>
    <SidebarProvider>
      <div className="flex min-h-screen w-full">
        <Sidebar collapsible="icon">
          <SidebarHeader>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton size="lg">
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                    <Activity className="h-4 w-4" />
                  </div>
                  <div className="grid flex-1 text-left leading-tight">
                    <span className="truncate font-semibold">Hiyori<span className="text-primary">DB</span></span>
                    <span className="truncate text-xs text-muted-foreground">{nav.desc}</span>
                  </div>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarHeader>
          <SidebarContent>
            {NAV_GROUPS.map(group => (
              <SidebarGroup key={group.label}>
                <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                <SidebarGroupContent>
                  <SidebarMenu>
                    {group.items.map(item => (
                      <SidebarMenuItem key={item.id}>
                        <SidebarMenuButton
                          isActive={view === item.id}
                          onClick={() => setView(item.id)}
                          tooltip={item.stage ? `${item.label} — ${item.stage}` : item.label}
                        >
                          <item.icon />
                          <span>{item.label}</span>
                        </SidebarMenuButton>
                      </SidebarMenuItem>
                    ))}
                  </SidebarMenu>
                </SidebarGroupContent>
              </SidebarGroup>
            ))}
          </SidebarContent>
          <SidebarFooter>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton isActive={view === 'settings'} onClick={() => setView('settings')} tooltip="Settings">
                  <SettingsIcon />
                  <span>Settings</span>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
            <div className="px-3 pb-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">hiyori-cli · v2 dashboard</div>
          </SidebarFooter>
          <SidebarRail />
        </Sidebar>

        <SidebarInset>
          <header className="flex h-12 shrink-0 items-center gap-3 border-b px-4">
            <SidebarTrigger />
            {/* The brand already lives in the sidebar, so the breadcrumb only
                carries the page name — repeating "HiyoriDB >" here was noise. */}
            <span className="text-sm font-medium">{nav.label}</span>
            <div className="ml-auto flex items-center gap-3">
              <PipelineChip />
              <PipelineChipDivider />
              <StatusChip />
            </div>
          </header>
          {/* A <section>, not a <main>: SidebarInset already renders the
              page's single <main> landmark, and nesting a second one gives
              screen-reader users two "main" regions to choose between. */}
          <section aria-label={nav.label} className="flex-1 p-6">
            <Suspense fallback={<PageFallback />}>
              {view === 'dashboard' && <DashboardPage />}
              {view === 'explorer' && <ErrorBoundary label="Explorer"><ExplorerPage /></ErrorBoundary>}
              {view === 'management' && <ManagementPage />}
              {view === 'quality' && <QualityPage />}
              {view === 'logs' && <LogsPage />}
              {view === 'publish' && <ErrorBoundary label="Publish"><PublishPage /></ErrorBoundary>}
              {view === 'settings' && <SettingsPage />}
            </Suspense>
          </section>
        </SidebarInset>
      </div>
      <AlertToasts />
    </SidebarProvider>
    </StatusProvider>
  )
}
