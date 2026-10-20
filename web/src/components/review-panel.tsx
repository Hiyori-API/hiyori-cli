import { useCallback, useEffect, useState } from 'react'
import { Check, ClipboardCheck, Inbox, X } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Section, StatStrip } from '@/components/widgets'
import { fetchRejections, postLabel, postRejection, type RejectionRow, type RejectionStats } from '@/lib/api'

/**
 * Human review of the pairs the combiner is unsure about.
 *
 * A human decision on those pairs is the only ground truth the ML gate can be
 * measured against — the "human F1" column in every benchmark table is empty
 * without it, and the model has been trained on a handful of labels.
 *
 * This surface was lost with the page consolidation: the API and the client
 * function were both still there, but nothing called them, so there was nowhere
 * to click. The buffer is filled by a combine run with `--rejection-buffer`,
 * which holds only the pairs the matcher genuinely could not decide.
 */
export function ReviewPanel() {
  const [rows, setRows] = useState<RejectionRow[]>([])
  const [stats, setStats] = useState<RejectionStats | undefined>(undefined)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    try {
      const r = await fetchRejections(50)
      setRows(r.rejections ?? [])
      setStats(r.stats)
    } catch {
      /* keep whatever was last shown rather than blanking the panel */
    }
  }, [])

  useEffect(() => {
    /* oxlint-disable-next-line react/set-state-in-effect -- the state updates
       inside an async fetch, after the await, so there is no synchronous
       setState in the effect itself. */
    void load()
    const timer = window.setInterval(load, 15000)
    return () => window.clearInterval(timer)
  }, [load])

  const decide = useCallback(
    async (row: RejectionRow, label: 0 | 1) => {
      const key = `${row.base_source}:${row.base_id}:${row.candidate_source}:${row.candidate_id}`
      setBusy(key)
      setError('')
      try {
        const body = {
          base_source: row.base_source,
          base_id: row.base_id,
          candidate_source: row.candidate_source,
          candidate_id: row.candidate_id,
          source: 'human',
        }
        const res = await postLabel({ ...body, label })
        if (res.error) {
          throw new Error(res.error)
        }
        // Record the decision on the buffered pair too, so it does not come back.
        await postRejection({
          ...body,
          decision: label === 1 ? 'approve' : 'reject',
          score: row.score ?? undefined,
        })
        await load()
      } catch (e) {
        setError(e instanceof Error ? e.message : 'could not record that decision')
      } finally {
        setBusy('')
      }
    },
    [load],
  )

  const pending = stats?.pending ?? rows.length

  return (
    <Section
      title="Review suggested merges"
      icon={ClipboardCheck}
      badge={pending > 0 ? <span className="text-xs tabular-nums text-muted-foreground">{pending}</span> : undefined}
    >
      <StatStrip
        items={[
          {
            label: 'Pending',
            value: String(stats?.pending ?? 0),
            icon: Inbox,
            tone: pending > 0 ? 'warn' : 'ok',
          },
          {
            label: 'Approved',
            value: String(stats?.by_decision?.approve ?? 0),
            icon: Check,
            tone: 'ok',
          },
          {
            label: 'Rejected',
            value: String(stats?.by_decision?.reject ?? 0),
            icon: X,
            tone: 'bad',
          },
        ]}
      />

      <p className="mt-2 text-xs text-muted-foreground">
        The matcher turned these pairs down — usually the titles were too far apart for its floor.
        Agreeing with one records it in the training labels, where the pair becomes a
        positive; disagreeing leaves the pair rejected.
      </p>

      {error ? <p className="mt-2 text-xs text-destructive">{error}</p> : null}

      {rows.length === 0 ? (
        <p className="mt-3 text-xs text-muted-foreground">
          Nothing is waiting for a decision. A combine run with{' '}
          <code className="mx-1">--rejection-buffer</code> fills this with the pairs the matcher could not
          decide on its own.
        </p>
      ) : (
        <ul className="mt-3 divide-y divide-border">
          {rows.map((row) => {
            const key = `${row.base_source}:${row.base_id}:${row.candidate_source}:${row.candidate_id}`
            const working = busy === key
            return (
              <li key={key} className="flex flex-wrap items-center gap-3 py-2">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm">{row.base_title || row.base_id}</p>
                  <p className="truncate text-xs text-muted-foreground">
                    {row.base_source} · {row.base_id}
                    {row.score != null ? ` · score ${row.score.toFixed(1)}` : ''}
                  </p>
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm">{row.candidate_title || row.candidate_id}</p>
                  <p className="truncate text-xs text-muted-foreground">
                    {row.candidate_source} · {row.candidate_id}
                  </p>
                </div>
                <div className="flex items-center gap-1">
                  <Button size="sm" variant="outline" disabled={working} onClick={() => void decide(row, 0)}>
                    <X className="mr-1.5 h-3.5 w-3.5" />
                    Not the same
                  </Button>
                  <Button size="sm" disabled={working} onClick={() => void decide(row, 1)}>
                    <Check className="mr-1.5 h-3.5 w-3.5" />
                    Same entry
                  </Button>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Section>
  )
}
