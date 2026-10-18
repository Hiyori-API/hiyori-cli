import { Checkbox as CheckboxPrimitive } from "@base-ui/react/checkbox"
import { cn } from "cn"
import { CheckIcon, MinusIcon } from "lucide-react"

/**
 * shadcn-style checkbox lookalike built on the Base UI checkbox primitive.
 *
 * Base UI renders a `<span>` with a visually-hidden `<input>`, sets
 * `data-checked` / `data-indeterminate` on the root and exposes
 * `onCheckedChange(checked)`. Falls through to the native input for focus and
 * keyboard support.
 */
function Checkbox({
  className,
  indeterminate = false,
  checked,
  onCheckedChange,
  ...props
}: CheckboxPrimitive.Root.Props & {
  indeterminate?: boolean
  className?: string
}) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        "flex size-4 shrink-0 cursor-pointer items-center justify-center rounded-[4px] border border-input bg-transparent text-primary-foreground transition-colors outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 data-[checked]:border-primary data-[checked]:bg-primary data-[indeterminate]:border-primary data-[indeterminate]:bg-primary dark:border-input dark:bg-input/30 dark:data-[checked]:border-primary dark:data-[checked]:bg-primary",
        className
      )}
      checked={checked}
      onCheckedChange={onCheckedChange}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        render={
          <span className="pointer-events-none [&>svg]:size-3.5 [&>svg]:shrink-0 [&>svg]:text-primary-foreground" />
        }
      >
        {indeterminate ? <MinusIcon /> : <CheckIcon />}
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }