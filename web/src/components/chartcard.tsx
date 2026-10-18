import {
  Bar, BarChart, CartesianGrid, Cell, ComposedChart, Funnel, FunnelChart,
  LabelList, Legend, PolarAngleAxis, PolarGrid, PolarRadiusAxis, Radar,
  RadarChart, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis,
} from 'recharts'
import { ChartNoAxesCombined } from 'lucide-react'
import { Section, TOOLTIP_STYLE } from './widgets'
import { EmptyState } from './empty'

export interface SeriesDef { key: string; color: string }
export type SeriesData = Array<Record<string, number | string>>

/** Pipeline funnel. */
export function PipelineFunnel({ stages }: { stages: { name: string; value: number; color: string; hint?: string }[] }) {
  return (
    <Section title="Pipeline" collapsible={false}>
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
    </Section>
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
    <Section title={title} collapsible={false}>
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
    </Section>
  )
}

/** Threshold sensitivity scatter + wall-time diverging bars. */
export function ThresholdScatter({ title, points }: {
  title: string
  points: Array<{ threshold: number; merges: number; organized: number }>
}) {
  return (
    <Section title={title} collapsible={false}>
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
    </Section>
  )
}

/** Diverging benchmark bars vs a baseline. */
export function BenchmarkBars({ title, rows }: { title: string; rows: Array<{ name: string; wall_s: number; merges?: number; speed?: number }> }) {
  const base = rows.length ? Math.max(...rows.map(r => r.wall_s)) : 1
  const data = rows.map((r, i) => ({ name: r.name, dev: (r.wall_s - base) / base * 100, wall_s: r.wall_s, merges: r.merges ?? 0, speed: r.speed ?? 0, idx: i }))
  return (
    <Section title={title} collapsible={false}>
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
    </Section>
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
    <Section title={title} collapsible={false}>
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
    </Section>
  )
}
