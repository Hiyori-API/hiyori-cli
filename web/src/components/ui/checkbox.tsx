import { Checkbox as CarbonCheckbox } from "@carbon/react"
import { useId } from "react"
import { cn } from "cn"

/**
 * Carbon `Checkbox` behind the dashboard's checkbox API.
 *
 * Carbon models the tri-state exactly the way the dashboard already did —
 * `checked` plus a separate `indeterminate` — so the bulk-selection checkboxes
 * in the Explorer map across directly. What does change is the handler: Carbon
 * reports through `onChange`, Base UI through `onCheckedChange`, so the
 * dashboard's name is kept and routed internally.
 *
 * Carbon requires both `id` and `labelText`; a generated id and an empty label
 * with `hideLabel` keep a bare checkbox bare, since these sit inside table
 * cells that already carry their own accessible name via `aria-label`.
 *
 * Carbon checkboxes are square with no radius, which is the shape the dashboard
 * wanted, so no override is needed.
 */
export interface CheckboxProps
  extends Omit<
    React.ComponentProps<typeof CarbonCheckbox>,
    "id" | "labelText" | "onChange" | "indeterminate"
  > {
  checked?: boolean
  indeterminate?: boolean
  /** Base UI's handler name; routed to Carbon's `onChange`. */
  onCheckedChange?: (checked: boolean) => void
  id?: string
}

function Checkbox({
  className,
  indeterminate = false,
  checked,
  onCheckedChange,
  id,
  ...props
}: CheckboxProps) {
  const generated = useId()
  return (
    <CarbonCheckbox
      data-slot="checkbox"
      id={id ?? generated}
      labelText=""
      hideLabel
      checked={indeterminate ? false : checked}
      indeterminate={indeterminate}
      onChange={(_evt, data) => {
        // Carbon hands back the post-toggle state; the dashboard's handler only
        // wants "is it checked now", so the indeterminate flag is folded in.
        const next = data as { checked?: boolean; indeterminate?: boolean } | undefined
        onCheckedChange?.(Boolean(next?.checked) && !next?.indeterminate)
      }}
      // See .carbon-checkbox in index.css: Carbon's label slot is hidden there,
      // because Tailwind v4 mis-escapes `__` inside arbitrary variants.
      className={cn("carbon-checkbox", className)}
      {...props}
    />
  )
}

export { Checkbox }
