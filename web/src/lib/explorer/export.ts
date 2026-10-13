/** CSV / JSONL export helpers for the Explorer. */

import type { ExploreRow } from '@/lib/api'
import { titleOf } from './doc-utils'

function esc(v: unknown): string {
  return `"${String(v ?? '').replace(/"/g, '""')}"`
}

export function buildCsv(rows: ExploreRow[], headings: string[]): string {
  const lines = [headings.map(esc).join(',')]
  rows.forEach(d => lines.push(headings.map(h => esc(h === 'title' ? titleOf(d) : JSON.stringify(d[h] ?? ''))).join(',')))
  return lines.join('\n')
}

export function buildJsonl(rows: ExploreRow[]): string {
  return rows.map(d => JSON.stringify(d)).join('\n')
}

export function downloadText(text: string, mime: string, filename: string): void {
  const blob = new Blob([text], { type: mime })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}