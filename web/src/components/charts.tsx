import { useEffect, useState } from 'react'

/**
 * Shared chart palette (single source of truth across pages).
 *
 * This was a hardcoded list of neon hexes (#ff5f57, #ff8fd8, …) while the
 * `--chart-1..5` tokens sat unused and grayscale, so charts looked like they
 * came from a different product than the rest of the UI.
 *
 * The values now live in index.css as `--chart-1..8` and are read back off the
 * document, because Recharts needs concrete colour strings (it measures and
 * slices SVG paths) and each theme needs its own lightness. Reading the token
 * keeps one source of truth instead of hardcoding the ramp twice.
 *
 * The ramp alternates hue and lightness so adjacent series stay distinguishable
 * even in greyscale and under common red/green colour vision deficiencies.
 */
const RAMP = [
  '--chart-1', '--chart-2', '--chart-3', '--chart-4',
  '--chart-5', '--chart-6', '--chart-7', '--chart-8',
] as const

/** Fallbacks used before stylesheets settle (SSR / first paint). */
const FALLBACK = [
  '#4c8dff', '#22b8a6', '#f2a33c', '#a06bf0',
  '#e5484d', '#3fb950', '#d67ab1', '#8b98a9',
]

function readToken(name: string, i: number): string {
  if (typeof window === 'undefined') return FALLBACK[i]
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return v || FALLBACK[i]
}

/**
 * Categorical series colours, in assignment order.
 *
 * Re-reads when the theme class flips, so charts re-render in the new theme's
 * lightness instead of keeping the colours they were mounted with.
 */
export function useChartColors(): string[] {
  const [colors, setColors] = useState<string[]>(() => RAMP.map(readToken))

  useEffect(() => {
    const root = document.documentElement
    const apply = () => setColors(RAMP.map(readToken))
    // The theme is a class on <html>, so watch that; re-read on the next frame
    // so the new custom-property values are already in the cascade.
    const mo = new MutationObserver(() => requestAnimationFrame(apply))
    mo.observe(root, { attributes: true, attributeFilter: ['class'] })
    apply()
    return () => mo.disconnect()
  }, [])

  return colors
}

/**
 * Semantic single-series colours. Charts that encode meaning (health, pass/fail)
 * should pick one of these by meaning rather than taking the next categorical
 * colour, which would imply "series #7".
 */
export function useSeriesColors() {
  const [s, setS] = useState(() => ({
    neutral: readToken('--chart-8', 7),
    info: readToken('--chart-1', 0),
    good: readToken('--chart-6', 5),
    warn: readToken('--chart-3', 2),
    bad: readToken('--chart-5', 4),
  }))

  useEffect(() => {
    const apply = () => setS({
      neutral: readToken('--chart-8', 7),
      info: readToken('--chart-1', 0),
      good: readToken('--chart-6', 5),
      warn: readToken('--chart-3', 2),
      bad: readToken('--chart-5', 4),
    })
    const mo = new MutationObserver(() => requestAnimationFrame(apply))
    mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
    apply()
    return () => mo.disconnect()
  }, [])

  return s
}
