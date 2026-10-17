import { useEffect, useState } from 'react'

export function useTheme() {
  const [dark, setDark] = useState<boolean>(() => {
    const saved = localStorage.getItem('hiyori-theme')
    return saved ? saved === 'dark' : true
  })

  useEffect(() => {
    const root = document.documentElement
    root.classList.toggle('dark', dark)
    // Carbon reads its theme from `data-carbon-theme` on <html>: `g100` is
    // Carbon's signature dark (gray 100 = #161616), `white` is the light
    // default. Driving it from the same toggle keeps shadcn-era `.dark` and
    // Carbon in lockstep, so nothing is half-themed.
    root.setAttribute('data-carbon-theme', dark ? 'g100' : 'white')
    localStorage.setItem('hiyori-theme', dark ? 'dark' : 'light')
  }, [dark])

  return { dark, toggle: () => setDark(d => !d) }
}
