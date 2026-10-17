import { Button as CarbonButton } from "@carbon/react"
import { cn } from "cn"

/**
 * Carbon `Button` behind the dashboard's existing button API.
 *
 * Variant maps onto Carbon's `kind`, which is a near one-to-one fit:
 * default→primary, outline→tertiary (Carbon's outlined button), ghost→ghost,
 * destructive→danger.
 *
 * Size deliberately does NOT pass straight through. Carbon is designed for
 * low-density enterprise UI where the default button is 48px tall; this
 * dashboard is a dense telemetry surface built around 24–32px controls and
 * dense tables, so the Carbon *visual language* (sharp corners, IBM blue,
 * square focus ring) is kept while the control heights stay where the layout
 * already expects them. `sm` (32px) is the Carbon default here.
 */
type Variant =
  | "default"
  | "outline"
  | "secondary"
  | "ghost"
  | "destructive"
  | "link"

type Size =
  | "default"
  | "xs"
  | "sm"
  | "lg"
  | "icon"
  | "icon-xs"
  | "icon-sm"
  | "icon-lg"

const KIND: Record<Variant, "primary" | "secondary" | "tertiary" | "ghost" | "danger"> = {
  default: "primary",
  outline: "tertiary",
  secondary: "secondary",
  ghost: "ghost",
  destructive: "danger",
  link: "tertiary",
}

/** Height/padding per dashboard size, layered over Carbon's own sizing. */
const SIZE_CLASS: Record<Size, string> = {
  default: "h-8 min-h-8 px-3",
  sm: "h-7 min-h-7 px-2.5",
  xs: "h-6 min-h-6 px-2 text-xs",
  lg: "h-10 min-h-10 px-4",
  icon: "h-8 min-h-8 w-8 p-0",
  "icon-sm": "h-7 min-h-7 w-7 p-0",
  "icon-xs": "h-6 min-h-6 w-6 p-0",
  "icon-lg": "h-10 min-h-10 w-10 p-0",
}

const ICON_SIZES = new Set<Size>(["icon", "icon-sm", "icon-xs", "icon-lg"])

export interface ButtonProps
  extends Omit<React.ComponentProps<typeof CarbonButton>, "kind" | "size"> {
  variant?: Variant
  size?: Size
}

function Button({
  className,
  variant = "default",
  size = "default",
  hasIconOnly,
  ...props
}: ButtonProps) {
  const iconOnly = hasIconOnly ?? ICON_SIZES.has(size)
  return (
    <CarbonButton
      data-slot="button"
      kind={KIND[variant]}
      // Carbon needs the size prop; we override the rendered height in CSS so
      // the dashboard keeps its density without giving up Carbon's component.
      size="sm"
      hasIconOnly={iconOnly}
      className={cn(
        // Carbon's 2px focus ring and sharp corners come from the component;
        // these keep the dense sizing and let the icon sit at the old size.
        SIZE_CLASS[size],
        "max-w-none [&_svg]:pointer-events-none [&_svg]:shrink-0",
        "[&_svg:not([class*='size-'])]:size-3.5",
        "[&_svg:not([class*='size-'])]:max-sm:size-3",
        variant === "link" && "underline-offset-4 hover:underline",
        className,
      )}
      {...props}
    />
  )
}

/** Kept for call sites that compose button styling by hand. */
const buttonVariants = ({ size = "default", className }: { variant?: Variant; size?: Size; className?: string } = {}) =>
  cn(SIZE_CLASS[size], className)

export { Button, buttonVariants }
export type { Variant as ButtonVariant, Size as ButtonSize }
