import { Tag as CarbonTag } from "@carbon/react"
import { cn } from "cn"

/**
 * Carbon `Tag` behind the dashboard's badge API.
 *
 * Carbon has no "destructive"/"ghost"/"link" tag, so those map onto the Carbon
 * types that carry the same meaning (red, cool-gray, outline) and the two that
 * have no Tag equivalent keep their token-driven styling — Carbon has no
 * colour that reads as "link", and an unstyled ghost tag is a plain label.
 *
 * Carbon tags are 16px tall with a 2px radius; the dashboard's badges were
 * 20px pills, so the height is set here rather than leaving a jarring jump.
 */
type Variant =
  | "default"
  | "secondary"
  | "destructive"
  | "outline"
  | "ghost"
  | "link"

const TAG_TYPE: Record<
  Exclude<Variant, "ghost" | "link">,
  "blue" | "cool-gray" | "red" | "outline"
> = {
  default: "blue",
  secondary: "cool-gray",
  destructive: "red",
  outline: "outline",
}

const TONE: Record<Variant, string> = {
  // `text-*` overrides let a light Carbon tag read correctly on the dark g100
  // surface, where Carbon's own default foreground would be too dim.
  default: "",
  secondary: "",
  destructive: "",
  outline: "",
  ghost: "border border-transparent bg-transparent text-muted-foreground",
  link: "border border-transparent bg-transparent text-primary underline-offset-4 hover:underline",
}

/** Carbon Tag colour types (see `TYPES` in @carbon/react Tag). */
type CarbonTagType =
  | "red"
  | "magenta"
  | "purple"
  | "blue"
  | "cyan"
  | "teal"
  | "green"
  | "gray"
  | "cool-gray"
  | "warm-gray"
  | "high-contrast"
  | "outline"

/**
 * Standard span props plus the Carbon Tag props the dashboard actually uses.
 * Carbon's own Tag props are a discriminated union that is narrower than what
 * these call sites pass (`title`, `className`, arbitrary children), so the
 * surface is widened here rather than edited at ~15 call sites.
 */
export interface BadgeProps extends React.ComponentProps<"span"> {
  variant?: Variant
  type?: CarbonTagType
  size?: "sm" | "md" | "lg"
  filter?: boolean
  onClose?: () => void
}

function Badge({ className, variant = "default", ...props }: BadgeProps) {
  const plain = variant === "ghost" || variant === "link"
  if (plain) {
    return (
      <span
        data-slot="badge"
        data-variant={variant}
        className={cn(
          "inline-flex h-5 w-fit shrink-0 items-center justify-center gap-1 whitespace-nowrap px-2 text-xs font-medium [&>svg]:pointer-events-none [&>svg]:size-3",
          TONE[variant],
          className,
        )}
        {...props}
      />
    )
  }

  return (
    <CarbonTag
      data-slot="badge"
      data-variant={variant}
      type={TAG_TYPE[variant]}
      size="sm"
      className={cn(
        "h-5 max-w-none rounded-[2px] px-2 text-xs font-medium [&>svg]:size-3",
        TONE[variant],
        className,
      )}
      {...props}
    />
  )
}

const badgeVariants = ({ variant = "default", className }: { variant?: Variant; className?: string } = {}) =>
  cn(TONE[variant], className)

export { Badge, badgeVariants }
export type { Variant as BadgeVariant }
