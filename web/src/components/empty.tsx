import type { LucideIcon } from 'lucide-react'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'

export function EmptyState({ icon: Icon, title, description, action, actionLabel, busy }: {
  icon: LucideIcon
  title: string
  description?: string
  action?: () => void
  actionLabel?: string
  /** The action is in flight — show a spinner and block a second click. */
  busy?: boolean
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
      {action && (
        <Button size="sm" onClick={action} disabled={busy}>
          {busy && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
          {actionLabel ?? 'Take action'}
        </Button>
      )}
    </div>
  )
}
