import { Toaster } from '@/components/ui/sonner'
import { Dashboard } from '@/components/Dashboard'

export default function App() {
  return (
    <>
      <Dashboard />
      <Toaster position="bottom-right" />
    </>
  )
}
