import { useState, type ReactNode } from 'react'
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, ComposedChart, Funnel, FunnelChart,
  LabelList, Legend, Line, PolarAngleAxis, PolarGrid, PolarRadiusAxis, Radar,
  RadarChart, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis,
} from 'recharts'
import { ChartLine, ChartNoAxesCombined, Logs } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { TOOLTIP_STYLE } from './widgets'
import { EmptyState } from './empty'

export interface SeriesDef { key: string; color: string }
export type SeriesData = Array<Record<string, number | string>>

/** Align multiple equally-pushed series by trailing index and produce chart rows. */
export function alignSeries(series: { key: string; data: number[] }[], stepSeconds = 3): SeriesData {
  const max = Math.max(0, ...series.map(s => s.data.length))
  if (!max) return []
  const rows: SeriesData = []
  for (let i = 0; i < max; i++) {
    const row: Record<string, number | string> = { label: `-${Math.round((max - i) * stepSeconds)}s` }
    series.forEach(s => {
      const idx = s.data.length - max + i
      row[s.key] = idx >= 0 ? (s.data[idx] ?? 0) : 0
    })
    rows.push(row)
  }
  return rows
}

/** Reusable line/area series card with log + area + window controls. */
export function SeriesChartCard({ title, series, data, emptyMsg, helper, icon }: {
  title: string
  series: SeriesDef[] & { key: string }[]
  data: SeriesData
  emptyMsg: string
  helper?: ReactNode
  icon?: ReactNode
}) {
  const [log, setLog] = useState(false)
  const [area, setArea] = useState(true)
  void icon
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle>
        <div className="flex items-center gap-1.5">
          {helper}
          <Button size="icon-sm" variant="ghost" title="Y-log" onClick={() => setLog(v => !v)} className={log ? 'text-primary' : ''}>
            <Logs className="h-3.5 w-3.5" />
          </Button>
          <Button size="icon-sm" variant="ghost" title="Area/line" onClick={() => setArea(v => !v)} className={area ? 'text-primary' : ''}>
            <ChartLine className="h-3.5 w-3.5" />
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        <div className="h-52">
          {data.length ? (
            <ResponsiveContainer width="100%" height="100%">
              <ComposedChart data={data} margin={{ top: 6, right: 8, bottom: 0, left: -14 }}>
                <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} minTickGap={40} />
                <YAxis scale={log ? 'log' : 'auto'} domain={log ? ['auto', 'auto'] : [0, 'auto']} tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                {series.map((s) =>
                  area
                    ? <Area key={s.key} type="monotone" dataKey={s.key} stroke={s.color} fill={s.color} fillOpacity={0.14} dot={false} strokeWidth={2} />
                    : <Line key={s.key} type="monotone" dataKey={s.key} stroke={s.color} dot={false} strokeWidth={2} />
                )}
              </ComposedChart>
            </ResponsiveContainer>
          ) : <EmptyState icon={ChartNoAxesCombined} title="Waiting for data" description={emptyMsg} />}
        </div>
      </CardContent>
    </Card>
  )
}

/** 100% stacked composition stream (source share over time). */
export function CompositionStream({ title, data, keys, colors, emptyMsg }: {
  title: string
  data: SeriesData
  keys: string[]
  colors: string[]
  emptyMsg: string
}) {
  return (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent>
        <div className="h-52">
          {data.length ? (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={data} margin={{ top: 6, right: 8, bottom: 0, left: -6 }} stackOffset="expand">
                <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} minTickGap={40} />
                <YAxis tickFormatter={(v: number) => `${Math.round(v * 100)}%`} tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                  {/* The legend grows with the series count; with 15 collections it
                      wrapped to three rows and squeezed the stream into a sliver.
                      Cap its height and let it scroll instead. */}
                  <Legend wrapperStyle={{ fontSize: 11, maxHeight: 44, overflowY: 'auto' }} iconSize={8} iconType="plainline" />
                {keys.map((k, i) => <Area key={k} type="monotone" dataKey={k} stackId="1" stroke={colors[i % colors.length]} fill={colors[i % colors.length]} fillOpacity={0.75} dot={false} />)}
              </AreaChart>
            </ResponsiveContainer>
          ) : <EmptyState icon={ChartNoAxesCombined} title="Waiting for data" description={emptyMsg} />}
        </div>
      </CardContent>
    </Card>
  )
}

/** Pipeline funnel. */
export function PipelineFunnel({ stages }: { stages: { name: string; value: number; color: string; hint?: string }[] }) {
  return (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Pipeline</CardTitle></CardHeader>
      <CardContent>
        <div className="h-48">
          <ResponsiveContainer width="100%" height="100%">
            <FunnelChart margin={{ top: 4, right: 8, bottom: 4, left: 8 }}>
              <Tooltip contentStyle={TOOLTIP_STYLE} />
              <Funnel dataKey="value" data={stages} isAnimationActive={false}>
                <LabelList position="right" fill="var(--muted-foreground)" stroke="none" dataKey="name" fontSize={11} />
                {stages.map((s, i) => <Cell key={i} fill={s.color} />)}
              </Funnel>
            </FunnelChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  )
}

/** Radial completeness radar. */
export function CompletenessRadar({ title, data, series, emptyMsg }: {
  title: string
  data: Array<Record<string, string | number>>
  series: { key: string; color: string }[]
  emptyMsg: string
}) {
  return (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent>
        <div className="h-64">
          {data.length ? (
            <ResponsiveContainer width="100%" height="100%">
              <RadarChart data={data} cx="50%" cy="50%" outerRadius="70%">
                <PolarGrid stroke="var(--border)" />
                <PolarAngleAxis dataKey="ref" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} />
                <PolarRadiusAxis domain={[0, 100]} tick={{ fontSize: 9, fill: 'var(--muted-foreground)' }} />
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                {series.map(s => <Radar key={s.key} name={s.key} dataKey={s.key} stroke={s.color} fill={s.color} fillOpacity={0.22} />)}
              </RadarChart>
            </ResponsiveContainer>
          ) : <EmptyState icon={ChartNoAxesCombined} title="No coverage data" description={emptyMsg} />}
        </div>
      </CardContent>
    </Card>
  )
}

/** Threshold sensitivity scatter + wall-time diverging bars. */
export function ThresholdScatter({ title, points }: {
  title: string
  points: Array<{ threshold: number; merges: number; organized: number }>
}) {
  return (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent>
        <div className="h-56">
          {points.length > 1 ? (
            <ResponsiveContainer width="100%" height="100%">
              <ScatterChart margin={{ top: 8, right: 8, bottom: 8, left: -14 }}>
                <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" />
                <XAxis type="number" dataKey="threshold" name="threshold" domain={[0, 100]} tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} />
                <YAxis type="number" dataKey="merges" name="merges" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ strokeDasharray: '3 3' }} />
                <Scatter data={points} fill="var(--chart-1)" />
              </ScatterChart>
            </ResponsiveContainer>
          ) : <EmptyState icon={ChartNoAxesCombined} title="No benchmark sweep" description="Run a threshold sweep to see the sensitivity curve." />}
        </div>
      </CardContent>
    </Card>
  )
}

/** Diverging benchmark bars vs a baseline. */
export function BenchmarkBars({ title, rows }: { title: string; rows: Array<{ name: string; wall_s: number; merges?: number; speed?: number }> }) {
  const base = rows.length ? Math.max(...rows.map(r => r.wall_s)) : 1
  const data = rows.map((r, i) => ({ name: r.name, dev: (r.wall_s - base) / base * 100, wall_s: r.wall_s, merges: r.merges ?? 0, speed: r.speed ?? 0, idx: i }))
  return (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent>
        <div className="h-56">
          {data.length ? (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, bottom: 4, left: 8 }}>
                <XAxis type="number" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} unit="%" />
                <YAxis type="category" dataKey="name" width={88} tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} />
                <Tooltip contentStyle={TOOLTIP_STYLE} formatter={(v: unknown, n: unknown) => [n === 'dev' ? `${Number(v).toFixed(1)}% vs baseline` : `${v}`, String(n)]} />
                <Bar dataKey="dev" radius={[0, 4, 4, 0]} isAnimationActive={false}>
                  {data.map(d => <Cell key={d.idx} fill={d.dev <= 0 ? 'var(--chart-6)' : 'var(--chart-5)'} />)}
                  <LabelList dataKey="wall_s" position="right" formatter={(v: unknown) => `${Math.round(Number(v) / 60)}m`} fill="var(--muted-foreground)" fontSize={10} />
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          ) : <EmptyState icon={ChartNoAxesCombined} title="No benchmark data" description="Run combine benchmarks to compare strategies." />}
        </div>
      </CardContent>
    </Card>
  )
}

/** Density histogram from fixed bins. */
export function DensityHistogram({ title, bins, counts, color = ['var(--chart-1)', 'var(--chart-2)'] }: {
  title: string
  bins: number[]
  counts: Record<string, number>
  color?: string[]
}) {
  const data = bins.map((b, i) => ({ bin: `${b}${b + 10 === 100 ? '–100' : `–${b + 10}`}`, n: counts[String(b)] ?? 0, idx: i }))
  return (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent>
        {data.some(d => d.n > 0) ? (
          <div className="h-44">
            <ResponsiveContainer width="100%" height="100%">
              <ComposedChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                <XAxis dataKey="bin" tick={{ fontSize: 9, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} interval={0} angle={-35} textAnchor="end" height={30} />
                {/* Counts here run into the thousands, so the axis keeps its full
                    width — a negative left margin clipped the tick labels. */}
                <YAxis tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                <Bar dataKey="n" name="records" radius={[3, 3, 0, 0]} isAnimationActive={false}>
                  {data.map(d => <Cell key={d.idx} fill={color[d.idx % color.length]} />)}
                </Bar>
              </ComposedChart>
            </ResponsiveContainer>
          </div>
        ) : <EmptyState icon={ChartNoAxesCombined} title="No distribution yet" description="Merged records with a score will fill the histogram." />}
        <p className="mt-1 text-[10px] text-muted-foreground">score bins · {data.reduce((a, d) => a + d.n, 0).toLocaleString()} records</p>
      </CardContent>
    </Card>
  )
}
