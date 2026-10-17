import { cn } from "cn"

/**
 * Loading placeholder, restyled to Carbon.
 *
 * Carbon's SkeletonPlaceholder is a fixed 100px-wide block and SkeletonText is
 * a fixed-width paragraph, neither of which can hold an arbitrary box size —
 * and these placeholders size themselves to whatever is loading (chart panels,
 * table rows, KPI cards). So the animation and Carbon's placeholder surface are
 * kept while the box stays fully controllable, which is the part that matters.
 */
function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      aria-hidden="true"
      className={cn("animate-pulse bg-muted", className)}
      {...props}
    />
  )
}

export { Skeleton }
