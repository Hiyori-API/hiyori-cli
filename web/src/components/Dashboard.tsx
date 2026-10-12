import { useState } from 'react'
import { Activity, Database, GitMerge, LayoutDashboard, ScrollText, Settings as SettingsIcon, SlidersHorizontal } from 'lucide-react'
import { Breadcrumb, BreadcrumbItem, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb'
import { Separator } from '@/components/ui/separator'
import {
  Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupContent, SidebarGroupLabel,
  SidebarHeader, SidebarInset, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarProvider, SidebarRail, SidebarTrigger,
} from '@/components/ui/sidebar'
import { DashboardPage } from './pages/DashboardPage'
import { ExplorerPage } from './pages/ExplorerPage'
import { ManagementPage } from './pages/ManagementPage'
import { QualityPage } from './pages/QualityPage'
import { LogsPage } from './pages/LogsPage'
import { SettingsPage } from './pages/SettingsPage'
import { AlertToasts } from './alert-toast'

type View = 'dashboard' | 'explorer' | 'management' | 'quality' | 'logs' | 'settings'

const NAV: Array<{ id: View; label: string; icon: typeof LayoutDashboard; desc: string }> = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, desc: 'Live telemetry' },
  { id: 'explorer', label: 'Explorer', icon: Database, desc: 'MongoDB browser' },
  { id: 'management', label: 'Management', icon: SlidersHorizontal, desc: 'Sources, scheduler, backups' },
  { id: 'quality', label: 'Quality & Analysis', icon: GitMerge, desc: 'Coverage, benchmarks, sampler' },
  { id: 'logs', label: 'Logs', icon: ScrollText, desc: 'Slow requests, validation, runs' },
  { id: 'settings', label: 'Settings', icon: SettingsIcon, desc: 'Preferences' },
]

export function Dashboard() {
  const [view, setView] = useState<View>('dashboard')
  const nav = NAV.find(n => n.id === view) ?? NAV[0]

  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full">
        <Sidebar collapsible="icon">
          <SidebarHeader>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton size="lg">
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
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
              <SidebarGroupLabel>Navigation</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {NAV.map(item => (
                    <SidebarMenuItem key={item.id}>
                      <SidebarMenuButton isActive={view === item.id} onClick={() => setView(item.id)} tooltip={item.label}>
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
            <div className="px-3 py-2 text-xs text-muted-foreground">
              <div className="flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />hiyori-cli · v2 dashboard
              </div>
            </div>
          </SidebarFooter>
          <SidebarRail />
        </Sidebar>

        <SidebarInset>
          <header className="flex h-14 shrink-0 items-center gap-2 border-b px-5">
            <SidebarTrigger />
            <Separator orientation="vertical" className="h-4" />
            <Breadcrumb>
              <BreadcrumbList>
                <BreadcrumbItem><BreadcrumbPage>HiyoriDB</BreadcrumbPage></BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem><BreadcrumbPage className="text-muted-foreground">{nav.label}</BreadcrumbPage></BreadcrumbItem>
              </BreadcrumbList>
            </Breadcrumb>
          </header>
          <main className="flex-1 p-6">
            {view === 'dashboard' && <DashboardPage />}
            {view === 'explorer' && <ExplorerPage />}
            {view === 'management' && <ManagementPage />}
            {view === 'quality' && <QualityPage />}
            {view === 'logs' && <LogsPage />}
            {view === 'settings' && <SettingsPage />}
          </main>
        </SidebarInset>
      </div>
      <AlertToasts />
    </SidebarProvider>
  )
}
