import { lazy, Suspense, useEffect, useState } from 'react'
import { Activity, Database, LayoutDashboard, Loader2, Settings as SettingsIcon } from 'lucide-react'
import {
  Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupContent,
  SidebarHeader, SidebarInset, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarProvider, SidebarRail, SidebarTrigger,
} from '@/components/ui/sidebar'
import { cn } from 'cn'
import { Notice } from './notice'
import { AlertToasts } from './alert-toast'
import { ErrorBoundary } from './error-boundary'
import { StatusProvider, useStatus } from '@/lib/status-context'
import { useSettings } from '@/lib/hooks'
import { isIngestJob, isJobLive, type Job } from '@/lib/api'

import { isRoute, navigate, useRoute, type Route } from '@/lib/router'

const DashboardPage = lazy(() => import('./pages/DashboardPage').then(m => ({ default: m.DashboardPage })))
const ExplorerPage = lazy(() => import('./pages/ExplorerPage').then(m => ({ default: m.ExplorerPage })))
const SettingsPage = lazy(() => import('./pages/SettingsPage').then(m => ({ default: m.SettingsPage })))

type View = Route

interface NavItem {
  id: View
  label: string
  icon: typeof LayoutDashboard
  desc: string
}

/**
 * Flat navigation, Settings first.
 *
 * Pipeline stage grouping was useful when seven surfaces competed for attention.
 * With Merge quality, Logs, Publish and Ingest removed there are three, so the
 * headings became more chrome than structure — a single list is easier to scan
 * at this size. Settings leads the list because the ingest surface it used to
 * sit beside is gone: its remaining controls are preferences, not a landing page.
 */
const NAV: NavItem[] = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, desc: 'Live telemetry' },
  { id: 'explorer', label: 'Explorer', icon: Database, desc: 'MongoDB browser' },
  { id: 'settings', label: 'Settings', icon: SettingsIcon, desc: 'Preferences' },
]

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
      <span className={cn('h-2 w-2 shrink-0 rounded-full', stalled ? 'bg-warn' : 'animate-pulse bg-emerald-500')} />
      <span className="max-w-40 truncate">{name}</span>
      <span className="text-muted-foreground/60">·</span>
      {stalled ? 'no progress' : `${rate.toFixed(1)}/s`}
    </span>
  )
}


/**
 * Backend-down notice, owned by the shell.
 *
 * Only the Dashboard used to say anything when /api/status failed; Management
 * and Quality destructured `error` and then ignored it, so a dead backend left
 * them rendering empty tables with no explanation. Putting this in the shell
 * means every page reports the same failure the same way, once.
 */
function ConnectionBanner() {
  const { error, data } = useStatus()
  if (!error) return null
  return (
    <Notice
      tone="warn"
      variant="line"
      className="rounded-none border-x-0 border-t-0"
      icon={<Loader2 className="h-3.5 w-3.5 animate-spin" />}
    >
      Backend unreachable — {error}. {data ? 'Showing the last data received.' : 'Retrying…'}
    </Notice>
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
      <span className={`h-2 w-2 rounded-full ${hollow ? 'border border-warn bg-transparent' : dot} ${pulse ? 'animate-pulse' : ''}`} />
      {label}
    </span>
  )
}

export function Dashboard() {
  useAppliedPreferences()
  // The route lives in the hash, so reload, Back/Forward and shared links all
  // land on the same page. `useRoute` reads `#/<route>` and ignores the query
  // half, which the Explorer owns.
  const view = useRoute()
  const nav = NAV.find(n => n.id === view) ?? NAV[0]

  // Cross-page navigation (e.g. Settings → "Open Publish page") without the
  // event needing to know about the URL. The detail stays a route id; the
  // router turns it into a history entry.
  useEffect(() => {
    const onNavigate = (e: Event) => {
      const detail = (e as CustomEvent<string>).detail
      if (isRoute(detail)) navigate(detail)
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
            <SidebarGroup>
              <SidebarGroupContent>
                <SidebarMenu>
                  {NAV.map(item => (
                    <SidebarMenuItem key={item.id}>
                      <SidebarMenuButton
                        isActive={view === item.id}
                        onClick={() => navigate(item.id)}
                        tooltip={item.desc}
                      >
                        <item.icon />
                        <span>{item.label}</span>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          </SidebarContent>
          <SidebarFooter>
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
          <ConnectionBanner />
          {/* A <section>, not a <main>: SidebarInset already renders the
              page's single <main> landmark, and nesting a second one gives
              screen-reader users two "main" regions to choose between. */}
          <section aria-label={nav.label} className="flex-1 p-6">
            <Suspense fallback={<PageFallback />}>
              {view === 'dashboard' && <DashboardPage />}
              {view === 'explorer' && <ErrorBoundary label="Explorer"><ExplorerPage /></ErrorBoundary>}
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
