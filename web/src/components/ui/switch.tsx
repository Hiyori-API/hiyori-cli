import { Switch as SwitchPrimitive } from "@base-ui/react/switch"
import { cn } from "cn"

/**
 * Controlled switch, restyled to Carbon.
 *
 * Deliberately NOT Carbon's `Toggle`: Carbon's ToggleProps has no `checked`
 * prop at all — it is uncontrolled only, managing its own state. Every switch
 * here is controlled, because each one writes to the server (Settings
 * preferences, Publish toggles), so a component that owns its own state cannot
 * drive them. The controlled primitive is kept and given Carbon's treatment
 * instead: a square-ish track (Carbon's switch geometry) rather than a pill,
 * IBM blue when on, and Carbon's layer gray when off.
 */
function Switch({
  className,
  size = "default",
  ...props
}: SwitchPrimitive.Root.Props & {
  size?: "sm" | "default"
}) {
  return (
    <SwitchPrimitive.Root
      data-slot="switch"
      data-size={size}
      className={cn(
        "peer group/switch relative inline-flex shrink-0 items-center border border-transparent transition-colors outline-none rounded-[2px] after:absolute after:-inset-x-3 after:-inset-y-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-0 aria-invalid:ring-2 aria-invalid:ring-destructive data-checked:bg-primary data-unchecked:bg-input dark:data-unchecked:bg-input/80 data-disabled:cursor-not-allowed data-disabled:opacity-50",
        size === "sm" ? "h-[14px] w-[24px]" : "h-[20px] w-[36px]",
        className
      )}
      {...props}
    >
      <SwitchPrimitive.Thumb
        data-slot="switch-thumb"
        className="pointer-events-none block bg-foreground transition-transform group-data-[size=default]/switch:size-4 group-data-[size=sm]/switch:size-3 group-data-[size=default]/switch:data-checked:translate-x-4 group-data-[size=sm]/switch:data-checked:translate-x-2.5 group-data-unchecked:translate-x-0.5 data-checked:bg-primary-foreground"
      />
    </SwitchPrimitive.Root>
  )
}

export { Switch }
