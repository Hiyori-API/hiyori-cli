import { Toaster } from '@/components/ui/sonner'
import { Dashboard } from '@/components/Dashboard'
import { SWUpdater } from '@/components/sw-update'
import { ErrorBoundary } from '@/components/error-boundary'

export default function App() {
  return (
    <>
      <ErrorBoundary label="Dashboard">
        <Dashboard />
      </ErrorBoundary>
      <SWUpdater />
      <Toaster position="bottom-right" />
    </>
  )
}
