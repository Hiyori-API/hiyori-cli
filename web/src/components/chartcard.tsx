import {
  Legend, PolarAngleAxis, PolarGrid, PolarRadiusAxis, Radar,
  RadarChart, ResponsiveContainer, Tooltip,
} from 'recharts'
import { ChartNoAxesCombined } from 'lucide-react'
import { Section, TOOLTIP_STYLE } from './widgets'
import { EmptyState } from './empty'

export interface SeriesDef { key: string; color: string }
export type SeriesData = Array<Record<string, number | string>>

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
