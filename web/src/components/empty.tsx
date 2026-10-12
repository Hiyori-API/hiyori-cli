import type { LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'

export function EmptyState({ icon: Icon, title, description, action, actionLabel }: {
  icon: LucideIcon
  title: string
  description?: string
  action?: () => void
  actionLabel?: string
}) {
  return (
    <div className="flex min-h-[160px] flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-6 text-center">
      <div className="flex h-11 w-11 items-center justify-center rounded-full bg-muted/60 text-muted-foreground">
        <Icon className="h-5 w-5" />
      </div>
      <div>
        <p className="text-sm font-medium">{title}</p>
        {description && <p className="mt-1 max-w-xs text-xs text-muted-foreground">{description}</p>}
      </div>
      {action && <Button size="sm" onClick={action}>{actionLabel ?? 'Take action'}</Button>}
    </div>
  )
}
