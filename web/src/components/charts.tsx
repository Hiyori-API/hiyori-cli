import {
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

const COLORS = ['#4fb3ff', '#3fd06a', '#e8b64c', '#9b7bff', '#ff8fd8', '#ff5f57', '#2fb5b0', '#a3b8cc']

interface Row { label: string; [k: string]: string | number }

export function MultiLine({ rows, series }: {
  rows: Row[]
  series: { key: string; color: string }[]
}) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={rows} margin={{ top: 6, right: 8, bottom: 0, left: -14 }}>
        <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} minTickGap={40} />
        <YAxis tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
        <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
        <Legend wrapperStyle={{ fontSize: 11 }} />
        {series.map(s => (
          <Line key={s.key} type="monotone" dataKey={s.key} stroke={s.color} dot={false} strokeWidth={2} />
        ))}
      </LineChart>
    </ResponsiveContainer>
  )
}

export { COLORS }
