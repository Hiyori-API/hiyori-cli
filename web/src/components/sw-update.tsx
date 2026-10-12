import { useEffect } from 'react'
import { toast } from 'sonner'

/**
 * Informs the user when a new build is deployed: the SW updates in the
 * background; once the new worker is waiting, offer a reload.
 */
export function SWUpdater() {
  useEffect(() => {
    if (!('serviceWorker' in navigator)) return

    const prompt = (reg: ServiceWorkerRegistration) => {
      if (!reg.waiting) return
      const dismissedKey = 'hiyori-sw-dismissed'
      if (sessionStorage.getItem(dismissedKey)) return
      // Keep showing until reload (per-session dismissal only).
      toast('New version available', {
        description: 'A new dashboard build has been deployed.',
        duration: 12000,
        action: {
          label: 'Reload',
          onClick: () => { void reg.waiting?.postMessage({ type: 'SKIP_WAITING' }); window.location.reload() },
        },
      })
    }

    navigator.serviceWorker.ready.then((reg) => {
      prompt(reg)
      let refreshing = false
      // Auto-refresh when the new SW takes control.
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!refreshing) {
          refreshing = true
          window.location.reload()
        }
      })

      if (reg.waiting) return
      reg.addEventListener('updatefound', () => {
        const installing = reg.installing
        if (!installing) return
        installing.addEventListener('statechange', () => {
          if (installing.state === 'installed' && navigator.serviceWorker.controller) {
            prompt(reg)
          }
        })
      })
    })
  }, [])

  return null
}
