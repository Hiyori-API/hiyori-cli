import { lazy, Suspense, useEffect, useState } from 'react'
import { Activity, Database, GitMerge, LayoutDashboard, Loader2, ScrollText, Settings as SettingsIcon, SlidersHorizontal, Upload } from 'lucide-react'
import {
  Header, HeaderName, SideNav, SideNavItems, SideNavLink, SkipToContent,
} from '@carbon/react'
import { AlertToasts } from './alert-toast'
import { ErrorBoundary } from './error-boundary'
import { StatusProvider, useStatus } from '@/lib/status-context'

const DashboardPage = lazy(() => import('./pages/DashboardPage').then(m => ({ default: m.DashboardPage })))
const ExplorerPage = lazy(() => import('./pages/ExplorerPage').then(m => ({ default: m.ExplorerPage })))
const ManagementPage = lazy(() => import('./pages/ManagementPage').then(m => ({ default: m.ManagementPage })))
const QualityPage = lazy(() => import('./pages/QualityPage').then(m => ({ default: m.QualityPage })))
const LogsPage = lazy(() => import('./pages/LogsPage').then(m => ({ default: m.LogsPage })))
const PublishPage = lazy(() => import('./pages/PublishPage').then(m => ({ default: m.PublishPage })))
const SettingsPage = lazy(() => import('./pages/SettingsPage').then(m => ({ default: m.SettingsPage })))

type View = 'dashboard' | 'explorer' | 'management' | 'quality' | 'logs' | 'publish' | 'settings'

const NAV: Array<{ id: View; label: string; icon: typeof LayoutDashboard; desc: string }> = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, desc: 'Live telemetry' },
  { id: 'explorer', label: 'Explorer', icon: Database, desc: 'MongoDB browser' },
  { id: 'management', label: 'Management', icon: SlidersHorizontal, desc: 'Sources, scheduler, backups' },
  { id: 'quality', label: 'Quality & Analysis', icon: GitMerge, desc: 'Coverage, benchmarks, sampler' },
  { id: 'logs', label: 'Logs', icon: ScrollText, desc: 'Slow requests, validation, runs' },
  { id: 'publish', label: 'Publish', icon: Upload, desc: 'Export & publish dump' },
  { id: 'settings', label: 'Settings', icon: SettingsIcon, desc: 'Preferences' },
]

/** Lazy page-load fallback: centered spinner while a chunk streams in. */
function PageFallback() {
  return (
    <div className="flex h-[60vh] items-center justify-center">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
    </div>
  )
}

/**
 * Connection state for the header chip: the dot's colour/hollow/pulse and the
 * label, derived in one place so they cannot disagree.
 *
 * Dot colours come from the Carbon ramp (green/red/cyan/amber) rather than the
 * stock Tailwind greens and reds, so status reads as part of the same palette
 * as the charts and tags.
 */
function useConnectionState(): { label: string; dot: string; hollow: boolean; pulse: boolean } {
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

  if (!online) return { label: 'offline · cached', dot: 'bg-destructive', hollow: false, pulse: false }
  if (!realtime) return { label: `polling · ${secs}s`, dot: 'bg-chart-2', hollow: false, pulse: false }
  if (sseOpen) return { label: `live · ${secs}s`, dot: 'bg-chart-2', hollow: false, pulse: true }
  return { label: `reconnecting (${sseReconnects})`, dot: 'bg-chart-3', hollow: true, pulse: false }
}

/** Single header connection chip (G7) — realtime toggle lives in Settings now. */
function StatusChip() {
  const { label, dot, hollow, pulse } = useConnectionState()
  return (
    <span role="status" title="Connection status" className="carbon-status tabular-nums">
      <span className={`h-2 w-2 shrink-0 rounded-full ${hollow ? 'border border-chart-3 bg-transparent' : dot} ${pulse ? 'animate-pulse' : ''}`} />
      {label}
    </span>
  )
}

export function Dashboard() {
  const [view, setView] = useState<View>('dashboard')
  const nav = NAV.find(n => n.id === view) ?? NAV[0]

  // Cross-page navigation (e.g. Settings → "Open Publish page") without a router.
  useEffect(() => {
    const onNavigate = (e: Event) => {
      const detail = (e as CustomEvent<string>).detail
      if (NAV.some(n => n.id === detail)) setView(detail as View)
    }
    window.addEventListener('hiyori:navigate', onNavigate)
    return () => window.removeEventListener('hiyori:navigate', onNavigate)
  }, [])

  return (
    <StatusProvider>
      {/* Carbon app shell: Header on top, fixed SideNav left, content in the
          `cds--content` region. Carbon owns the geometry (48px header, 16rem
          side nav), so the layout below only places the three regions. */}
      <div className="cds--body carbon-shell min-h-screen">
        <SkipToContent />
        <Header aria-label="HiyoriDB">
          <HeaderName prefix="Hiyori">DB</HeaderName>
          {/* Carbon's HeaderGlobalBar is not a component — it is the
              `cds--header__global` wrapper that right-aligns the header's
              trailing content.

              The connection chip lives here directly rather than inside a
              HeaderGlobalAction: that action is a fixed 48x48 *icon* slot, and
              "live · 3s" does not fit inside it (it clipped). Carbon reserves
              those for icon actions (alerts, help, account); a live status is
              header text, so it is laid out as text. */}
          <div className="cds--header__global ml-auto flex items-center gap-1 px-4">
            <span className="sr-only">Connection status: </span>
            <StatusChip />
          </div>
        </Header>

        <SideNav aria-label="Page" expanded className="carbon-sidenav">
          <SideNavItems>
            <li className="carbon-sidenav-brand">
              <div className="flex items-center gap-2.5 px-4 py-4">
                <span className="flex h-7 w-7 items-center justify-center bg-primary text-primary-foreground">
                  <Activity className="h-4 w-4" />
                </span>
                <span className="leading-tight">
                  <span className="block text-sm font-semibold">HiyoriDB</span>
                  <span className="block text-xs text-muted-foreground">{nav.desc}</span>
                </span>
              </div>
            </li>
            {NAV.map(item => (
              <SideNavLink
                key={item.id}
                isActive={view === item.id}
                onClick={() => setView(item.id)}
                renderIcon={() => <item.icon className="h-4 w-4" />}
              >
                {item.label}
              </SideNavLink>
            ))}
          </SideNavItems>
        </SideNav>

        <main className="cds--content carbon-content">
          <Suspense fallback={<PageFallback />}>
            {view === 'dashboard' && <DashboardPage />}
            {view === 'explorer' && <ErrorBoundary label="Explorer"><ExplorerPage /></ErrorBoundary>}
            {view === 'management' && <ManagementPage />}
            {view === 'quality' && <QualityPage />}
            {view === 'logs' && <LogsPage />}
            {view === 'publish' && <ErrorBoundary label="Publish"><PublishPage /></ErrorBoundary>}
            {view === 'settings' && <SettingsPage />}
          </Suspense>
        </main>
      </div>
      <AlertToasts />
    </StatusProvider>
  )
}
