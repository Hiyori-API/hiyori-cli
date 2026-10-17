import { Separator as SeparatorPrimitive } from "@base-ui/react/separator"
import { cn } from "cn"

/**
 * Divider, restyled to Carbon.
 *
 * Carbon has no Separator component: it draws rules as layer tokens, so the
 * separator reads as Carbon's `layer-01` border rather than a component. The
 * controlled Base UI primitive is kept because Carbon offers no equivalent,
 * and it is a plain `<hr>` with no behaviour of its own.
 */
function Separator({
  className,
  orientation = "horizontal",
  ...props
}: SeparatorPrimitive.Props) {
  return (
    <SeparatorPrimitive
      data-slot="separator"
      orientation={orientation}
      className={cn(
        "shrink-0 bg-border data-horizontal:h-px data-horizontal:w-full data-vertical:w-px data-vertical:self-stretch",
        className
      )}
      {...props}
    />
  )
}

export { Separator }
