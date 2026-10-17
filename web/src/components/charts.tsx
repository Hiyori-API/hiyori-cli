/**
 * Shared chart palette (single source of truth across pages).
 *
 * Carbon's categorical ramp, 40/50 step, in Carbon's documented order:
 * blue → cyan → teal → purple → magenta → red → orange → yellow → green → gray.
 * These steps are the ones Carbon specifies as legible on both the white and
 * the g100 surfaces, so charts read the same in either theme.
 */
export const COLORS = [
  '#4589ff', // blue
  '#00bcd4', // cyan
  '#009d9a', // teal
  '#8a3ffc', // purple
  '#d12771', // magenta
  '#da1e28', // red
  '#ff832b', // orange
  '#f1c21b', // yellow
  '#42be65', // green
  '#8d8d8d', // gray
]

/** Named ramp entries, for call sites that mean a specific role, not "next colour". */
export const CARBON = {
  blue: '#4589ff',
  cyan: '#00bcd4',
  teal: '#009d9a',
  purple: '#8a3ffc',
  magenta: '#d12771',
  red: '#da1e28',
  orange: '#ff832b',
  yellow: '#f1c21b',
  green: '#42be65',
  gray: '#8d8d8d',
} as const
