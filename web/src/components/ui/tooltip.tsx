import { Tooltip as CarbonTooltip } from '@carbon/react'

type CarbonTooltipProps = React.ComponentProps<typeof CarbonTooltip>

/**
 * Carbon `Tooltip`.
 *
 * Carbon takes a `label` plus the trigger as its single child, rather than
 * Base UI's split Root/Trigger/Popup, so the compound API is gone and the four
 * call sites were collapsed to this shape. Carbon's tooltip is a flat square
 * layer with no arrow, which is the Carbon treatment.
 *
 * Carbon clones its child and attaches its own handlers, so the trigger must
 * be a single element that forwards props (a fragment or bare text will not get
 * the tooltip).
 */
export interface TooltipProps extends Omit<CarbonTooltipProps, 'children'> {
  label: CarbonTooltipProps['label']
  children: CarbonTooltipProps['children']
}

function Tooltip({ label, children, ...props }: TooltipProps) {
  return (
    <CarbonTooltip label={label} {...props}>
      {children}
    </CarbonTooltip>
  )
}

export { Tooltip }
