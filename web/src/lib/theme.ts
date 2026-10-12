import { useEffect, useState } from 'react'

export function useTheme() {
  const [dark, setDark] = useState<boolean>(() => {
    const saved = localStorage.getItem('hiyori-theme')
    return saved ? saved === 'dark' : true
  })

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark)
    localStorage.setItem('hiyori-theme', dark ? 'dark' : 'light')
  }, [dark])

  return { dark, toggle: () => setDark(d => !d) }
}
