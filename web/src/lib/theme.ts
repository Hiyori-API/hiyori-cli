import { useEffect, useState } from 'react'

export function useTheme() {
  const [dark, setDark] = useState<boolean>(() => {
    const saved = localStorage.getItem('hiyori-theme')
    return saved ? saved === 'dark' : true
  })

  useEffect(() => {
    const root = document.documentElement
    root.classList.toggle('dark', dark)
    // Carbon's precompiled stylesheet keys its themes off the `cds--g100`
    // CLASS on <html> — `data-carbon-theme` is only honoured by the Sass build,
    // which this app does not use. `cds--g100` is Carbon's signature dark
    // (gray 100 = #161616); without it Carbon components stay on the light
    // theme, which is how disabled icon colours were resolving to
    // rgba(22,22,22,0.25) — light-theme black at 25% — on a dark surface.
    // Driving it from the same toggle keeps `.dark` and Carbon in lockstep.
    root.classList.toggle('cds--g100', dark)
    localStorage.setItem('hiyori-theme', dark ? 'dark' : 'light')
  }, [dark])

  return { dark, toggle: () => setDark(d => !d) }
}
