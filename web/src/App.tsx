import { Toaster } from '@/components/ui/sonner'
import { Dashboard } from '@/components/Dashboard'
import { SWUpdater } from '@/components/sw-update'

export default function App() {
  return (
    <>
      <Dashboard />
      <SWUpdater />
      <Toaster position="bottom-right" />
    </>
  )
}
